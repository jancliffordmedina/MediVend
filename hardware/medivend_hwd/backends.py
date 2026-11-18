"""Device backends.

SimBackend generates synthetic edges through the SAME decoder/beam logic the
real hardware uses, and always reports itself as simulated. RealBackend uses
libgpiod (character device, v2 or v1 Python bindings) and the PCA9685 driver.
No pin numbers are assumed: everything comes from the validated config.
"""
from __future__ import annotations

import logging
import threading
import time
from collections import deque
from typing import Callable, Optional

from .config import Config, LineConfig
from .pca9685 import PCA9685

EdgeCallback = Callable[[bool, int, int], None]  # (active, event_ts_ns, monotonic_ns)
log = logging.getLogger("medivend_hwd.backend")

BEAM_SCENARIOS = ("pass", "none", "jam", "double", "late")


class Backend:
    simulated = False

    def start(self, on_coin: EdgeCallback, on_beam: EdgeCallback) -> None: raise NotImplementedError
    def probe(self) -> dict[str, Optional[bool]]: raise NotImplementedError
    def beam_blocked(self) -> bool: raise NotImplementedError
    def estop_engaged(self) -> Optional[bool]: raise NotImplementedError
    def set_outputs_enabled(self, enabled: bool) -> None: raise NotImplementedError
    def set_servo_pulse(self, channel: int, pulse_us: Optional[float]) -> None: raise NotImplementedError
    def all_off(self) -> None: raise NotImplementedError
    def dispense_started(self, compartment: int) -> None: pass
    def close(self) -> None: pass


# ----------------------------------------------------------------------------
class SimBackend(Backend):
    simulated = True

    def __init__(self, config: Config):
        self.config = config
        self._on_coin: Optional[EdgeCallback] = None
        self._on_beam: Optional[EdgeCallback] = None
        self._beam_blocked = False
        self._scenarios: deque[str] = deque()
        self._coin_queue: deque[tuple[int, float]] = deque()
        self._coin_thread: Optional[threading.Thread] = None
        self._lock = threading.Lock()
        self.outputs_enabled = False
        self.servo_writes: list[tuple[int, Optional[float]]] = []
        self.estop = False

    def start(self, on_coin: EdgeCallback, on_beam: EdgeCallback) -> None:
        self._on_coin, self._on_beam = on_coin, on_beam

    def probe(self) -> dict[str, Optional[bool]]:
        return {"servo_driver": True, "coin_input": True, "beam_input": True, "output_enable": True,
                "estop": True if self.config.safety.estop else None}

    def beam_blocked(self) -> bool:
        return self._beam_blocked

    def estop_engaged(self) -> Optional[bool]:
        return self.estop if (self.config.safety.estop or self.estop) else None

    def set_outputs_enabled(self, enabled: bool) -> None:
        self.outputs_enabled = enabled

    def set_servo_pulse(self, channel: int, pulse_us: Optional[float]) -> None:
        with self._lock:
            self.servo_writes.append((channel, pulse_us))

    def all_off(self) -> None:
        self.outputs_enabled = False

    # -- simulation controls --------------------------------------------------
    def queue_beam(self, scenario: str) -> None:
        if scenario not in BEAM_SCENARIOS:
            raise ValueError(f"beam scenario must be one of {BEAM_SCENARIOS}")
        with self._lock:
            self._scenarios.append(scenario)

    def inject_coin(self, centavos: Optional[int] = None, pulses: Optional[int] = None, pulse_ms: Optional[float] = None) -> int:
        if pulses is None:
            lookup = {value: count for count, value in self.config.coin.pulses.items()}
            if centavos not in lookup:
                raise ValueError("unsupported simulated denomination")
            pulses = lookup[centavos]
        if not 1 <= pulses <= 100:
            raise ValueError("pulses must be 1-100")
        width = (pulse_ms if pulse_ms is not None else self.config.sim.pulse_ms) / 1000
        with self._lock:
            # A real acceptor validates one coin at a time: serialise simulated
            # coins so their pulse trains never interleave.
            self._coin_queue.append((pulses, width))
            if self._coin_thread is None or not self._coin_thread.is_alive():
                self._coin_thread = threading.Thread(target=self._coin_worker, name="sim-coin", daemon=True)
                self._coin_thread.start()
        return pulses

    def _coin_worker(self) -> None:
        gap = self.config.sim.pulse_gap_ms / 1000
        between = (self.config.coin.train_gap_ms * 2) / 1000
        while True:
            with self._lock:
                if not self._coin_queue:
                    self._coin_thread = None
                    return
                pulses, width = self._coin_queue.popleft()
            for _ in range(pulses):
                self._emit(self._on_coin, True)
                time.sleep(width)
                self._emit(self._on_coin, False)
                time.sleep(gap)
            time.sleep(between)

    def _emit(self, callback: Optional[EdgeCallback], active: bool) -> None:
        if callback:
            now = time.monotonic_ns()
            callback(active, now, now)

    def _set_beam(self, blocked: bool) -> None:
        self._beam_blocked = blocked
        self._emit(self._on_beam, blocked)

    def dispense_started(self, compartment: int) -> None:
        with self._lock:
            scenario = self._scenarios.popleft() if self._scenarios else self.config.sim.default_beam
        delay = self.config.sim.beam_delay_ms / 1000
        block = self.config.sim.beam_block_ms / 1000
        log.info("simulated beam scenario=%s compartment=%s", scenario, compartment)

        def passage(after: float, duration: float) -> None:
            time.sleep(after)
            self._set_beam(True)
            time.sleep(duration)
            self._set_beam(False)

        if scenario == "pass":
            target, args = passage, (delay, block)
        elif scenario == "double":
            def target() -> None:  # type: ignore[misc]
                passage(delay, block)
                passage(block * 2, block)
            args = ()
        elif scenario == "jam":
            target, args = passage, (delay, self.config.beam.max_block_ms / 1000 + 0.5)
        elif scenario == "late":
            comp = self.config.compartments[compartment]
            late = (2 * comp.move_ms + comp.hold_ms + self.config.beam.watch_after_return_ms) / 1000 + 0.3
            target, args = passage, (late, block)
        else:
            return
        threading.Thread(target=target, args=args, name="sim-beam", daemon=True).start()


# ----------------------------------------------------------------------------
class _GpioLine:
    """One GPIO line through libgpiod; supports the v2 and v1 Python APIs."""

    def __init__(self, spec: LineConfig, output: Optional[bool] = None, edges: bool = False):
        import gpiod  # lazily imported; not needed by the simulator

        self.spec = spec
        self._gpiod = gpiod
        self.v2 = hasattr(gpiod, "request_lines")
        if self.v2:
            from gpiod.line import Bias, Direction, Edge, Value

            self._Value = Value
            bias = {"as-is": Bias.AS_IS, "disabled": Bias.DISABLED, "pull-up": Bias.PULL_UP, "pull-down": Bias.PULL_DOWN}[spec.bias]
            if output is None:
                settings = gpiod.LineSettings(direction=Direction.INPUT, bias=bias, active_low=spec.active_low,
                                              edge_detection=Edge.BOTH if edges else Edge.NONE)
            else:
                settings = gpiod.LineSettings(direction=Direction.OUTPUT, active_low=spec.active_low,
                                              output_value=Value.ACTIVE if output else Value.INACTIVE)
            self._request = gpiod.request_lines(spec.chip, consumer="medivend-hwd", config={spec.line: settings})
        else:
            chip = gpiod.Chip(spec.chip)
            self._line = chip.get_line(spec.line)
            flags = gpiod.LINE_REQ_FLAG_ACTIVE_LOW if spec.active_low else 0
            bias_flags = {"as-is": 0, "disabled": getattr(gpiod, "LINE_REQ_FLAG_BIAS_DISABLE", 0),
                          "pull-up": getattr(gpiod, "LINE_REQ_FLAG_BIAS_PULL_UP", 0),
                          "pull-down": getattr(gpiod, "LINE_REQ_FLAG_BIAS_PULL_DOWN", 0)}[spec.bias]
            if output is None:
                kind = gpiod.LINE_REQ_EV_BOTH_EDGES if edges else gpiod.LINE_REQ_DIR_IN
                self._line.request(consumer="medivend-hwd", type=kind, flags=flags | bias_flags)
            else:
                self._line.request(consumer="medivend-hwd", type=gpiod.LINE_REQ_DIR_OUT, flags=flags, default_vals=[1 if output else 0])

    def active(self) -> bool:
        if self.v2:
            return self._request.get_value(self.spec.line) == self._Value.ACTIVE
        return bool(self._line.get_value())

    def set_active(self, active: bool) -> None:
        if self.v2:
            self._request.set_value(self.spec.line, self._Value.ACTIVE if active else self._Value.INACTIVE)
        else:
            self._line.set_value(1 if active else 0)

    def wait_edges(self, timeout_s: float) -> list[tuple[bool, int]]:
        """Block up to timeout; return [(active, kernel_ts_ns)] (active-low already applied)."""
        if self.v2:
            from datetime import timedelta

            if not self._request.wait_edge_events(timedelta(seconds=timeout_s)):
                return []
            return [(event.event_type == event.Type.RISING_EDGE, event.timestamp_ns)
                    for event in self._request.read_edge_events()]
        seconds = int(timeout_s)
        if not self._line.event_wait(sec=seconds, nsec=int((timeout_s - seconds) * 1e9)):
            return []
        event = self._line.event_read()
        return [(event.type == self._gpiod.LineEvent.RISING_EDGE, event.sec * 1_000_000_000 + event.nsec)]

    def close(self) -> None:
        try:
            (self._request if self.v2 else self._line).release()
        except Exception:  # pragma: no cover - best effort on shutdown
            pass


class RealBackend(Backend):
    simulated = False

    def __init__(self, config: Config):
        self.config = config
        self._stop = threading.Event()
        self._threads: list[threading.Thread] = []
        self._errors: dict[str, str] = {}
        # Output-enable first, held DISABLED, before the PWM chip is touched.
        self.oe = _GpioLine(config.safety.output_enable, output=False) if config.safety.output_enable else None
        self.estop = _GpioLine(config.safety.estop) if config.safety.estop else None
        assert config.coin.input and config.beam.input  # enforced by config validation
        self.coin = _GpioLine(config.coin.input, edges=True)
        self.beam = _GpioLine(config.beam.input, edges=True)
        self.pwm = PCA9685.open(config.servo.i2c_bus, config.servo.address, config.servo.frequency_hz, config.servo.oscillator_hz)
        self.pwm.initialise()

    def _reader(self, name: str, line: _GpioLine, callback: EdgeCallback) -> None:
        while not self._stop.is_set():
            try:
                for active, ts in line.wait_edges(0.1):
                    callback(active, ts, time.monotonic_ns())
                self._errors.pop(name, None)
            except Exception as error:  # keep reading; surface in health
                self._errors[name] = str(error)
                log.exception("gpio reader %s failed", name)
                time.sleep(0.5)

    def start(self, on_coin: EdgeCallback, on_beam: EdgeCallback) -> None:
        for name, line, callback in (("coin", self.coin, on_coin), ("beam", self.beam, on_beam)):
            thread = threading.Thread(target=self._reader, args=(name, line, callback), name=f"gpio-{name}", daemon=True)
            thread.start()
            self._threads.append(thread)

    def probe(self) -> dict[str, Optional[bool]]:
        return {"servo_driver": self.pwm.probe(), "coin_input": "coin" not in self._errors,
                "beam_input": "beam" not in self._errors, "output_enable": True if self.oe else None,
                "estop": True if self.estop else None}

    def beam_blocked(self) -> bool:
        return self.beam.active()

    def estop_engaged(self) -> Optional[bool]:
        return None if self.estop is None else self.estop.active()

    def set_outputs_enabled(self, enabled: bool) -> None:
        if self.oe:
            self.oe.set_active(enabled)

    def set_servo_pulse(self, channel: int, pulse_us: Optional[float]) -> None:
        self.pwm.set_pulse_us(channel, pulse_us)

    def all_off(self) -> None:
        errors = []
        for action in (lambda: self.set_outputs_enabled(False), self.pwm.all_off):
            try:
                action()
            except Exception as error:
                errors.append(str(error))
        if errors:
            raise RuntimeError("; ".join(errors))

    def close(self) -> None:
        self._stop.set()
        for thread in self._threads:
            thread.join(timeout=1)
        try:
            self.all_off()
        finally:
            for line in (self.coin, self.beam, self.oe, self.estop):
                if line:
                    line.close()
            self.pwm.close()


class CoinTestBackend(SimBackend):
    """REAL coin acceptor on GPIO; servos and IR beam still simulated.

    For testing the physical coin slot through the whole kiosk payment flow
    before the servos/sensor are wired. Reports simulated = true, so PHP only
    accepts it in MEDIVEND_MODE=device_simulation (demo inventory, badge shown).
    """

    def __init__(self, config: Config, line_factory: Callable[..., "_GpioLine"] = None):  # type: ignore[assignment]
        super().__init__(config)
        assert config.coin.input  # enforced by config validation
        self.coin_line = (line_factory or _GpioLine)(config.coin.input, edges=True)
        self._stop = threading.Event()
        self._reader_thread: Optional[threading.Thread] = None
        self._coin_error: Optional[str] = None

    def start(self, on_coin: EdgeCallback, on_beam: EdgeCallback) -> None:
        super().start(on_coin, on_beam)

        def read() -> None:
            while not self._stop.is_set():
                try:
                    for active, ts in self.coin_line.wait_edges(0.1):
                        on_coin(active, ts, time.monotonic_ns())
                    self._coin_error = None
                except Exception as error:  # keep reading; surface in health
                    self._coin_error = str(error)
                    log.exception("coin acceptor reader failed")
                    time.sleep(0.5)

        self._reader_thread = threading.Thread(target=read, name="gpio-coin", daemon=True)
        self._reader_thread.start()

    def probe(self) -> dict[str, Optional[bool]]:
        return {**super().probe(), "coin_input": self._coin_error is None}

    def inject_coin(self, centavos: Optional[int] = None, pulses: Optional[int] = None, pulse_ms: Optional[float] = None) -> int:
        raise ValueError("the real coin acceptor is in use; insert a physical coin")

    def close(self) -> None:
        self._stop.set()
        if self._reader_thread:
            self._reader_thread.join(timeout=1)
        self.coin_line.close()


def create_backend(config: Config) -> Backend:
    if config.backend == "coin_test":
        return CoinTestBackend(config)
    return SimBackend(config) if config.simulated else RealBackend(config)
