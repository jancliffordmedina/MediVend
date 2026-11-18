"""Service core: coin intake, single-command dispensing, safety, health.

Invariants (mirroring the PHP hardware boundary):
  * A coin is journaled before it is visible; PHP acknowledges after commit.
  * A command ID is persisted before any motor output and is never actuated
    twice. Restart with an in-flight command reports `uncertain`, never retries.
  * Only a single beam passage inside the cycle window is `sensor_confirmed`.
    Motor movement alone is never reported as delivery.
  * stop() aborts motion and de-energises outputs; it is not latched. A latched
    maintenance lockout and the E-stop input both make the service not-ready.
"""
from __future__ import annotations

import logging
import re
import threading
import time
from typing import Any, Optional

from . import __version__
from .backends import Backend, SimBackend
from .config import REQUIRED_DENOMINATIONS, Config
from .journal import CommandConflict, Journal
from .pca9685 import angle_to_pulse_us
from .signals import MS, BeamWindow, CoinDecoder

log = logging.getLogger("medivend_hwd.service")
HEX64 = re.compile(r"\A[a-f0-9]{64}\Z")


class ServiceError(Exception):
    def __init__(self, status: int, code: str, message: str):
        super().__init__(message)
        self.status, self.code, self.message = status, code, message


class HardwareService:
    def __init__(self, config: Config, backend: Backend, journal: Journal):
        self.config, self.backend, self.journal = config, backend, journal
        c = config.coin
        self.decoder = CoinDecoder(c.pulses, c.glitch_ms, c.pulse_min_ms, c.pulse_max_ms, c.train_gap_ms)
        self._lock = threading.RLock()
        self._abort = threading.Event()
        self._shutdown = threading.Event()
        self._active: Optional[str] = None
        self._window: Optional[BeamWindow] = None
        self._cycle: Optional[threading.Thread] = None
        self._idle_blocked_since: Optional[int] = None
        self._last_error: Optional[str] = None
        self._started = time.time()
        self._ticker = threading.Thread(target=self._tick_loop, name="coin-ticker", daemon=True)

    # -- lifecycle ------------------------------------------------------------
    def start(self) -> None:
        self.backend.all_off()
        for command in self.journal.in_flight_commands():
            # The process stopped mid-cycle: a motor may or may not have moved.
            self.journal.finish_command(command["command_id"], "uncertain", "service restarted during the dispensing cycle")
            log.warning("in-flight command %s marked uncertain after restart", command["command_id"])
        self.backend.start(self._coin_edge, self._beam_edge)
        self._ticker.start()
        log.info("hardware service started backend=%s simulated=%s version=%s", self.config.backend, self.backend.simulated, __version__)

    def close(self) -> None:
        self._shutdown.set()
        self.stop("service shutdown")
        if self._cycle:
            self._cycle.join(timeout=5)
        self.backend.close()

    # -- inputs ---------------------------------------------------------------
    def _coin_edge(self, active: bool, ts_ns: int, mono_ns: int) -> None:
        self.decoder.edge(active, ts_ns, mono_ns)

    def _beam_edge(self, blocked: bool, ts_ns: int, mono_ns: int) -> None:
        window = self._window
        if window is not None:
            window.edge(blocked, ts_ns, mono_ns)
        self._idle_blocked_since = mono_ns if blocked else None

    def _tick_loop(self) -> None:
        while not self._shutdown.wait(0.02):
            try:
                result = self.decoder.poll(time.monotonic_ns())
                if result is None:
                    continue
                if result.anomaly:
                    anomaly_id = self.journal.record_anomaly("coin", f"{result.anomaly} ({result.pulses} pulses)")
                    log.error("coin anomaly #%s: %s pulses=%s", anomaly_id, result.anomaly, result.pulses)
                else:
                    event = self.journal.record_coin(result.centavos)  # type: ignore[arg-type]
                    log.info("coin accepted centavos=%s pulses=%s event=%s", result.centavos, result.pulses, event["event_id"])
            except Exception as error:  # journal failure: surfaced as not-ready
                self._last_error = f"coin journal: {error}"
                log.exception("coin processing failed")

    # -- health ---------------------------------------------------------------
    def health(self) -> dict[str, Any]:
        probe = self.backend.probe()
        journal_ok = self.journal.healthy()
        counts = self.journal.counts() if journal_ok else {"unacknowledged_events": None, "open_anomalies": None}
        lockout = self.journal.meta("lockout") if journal_ok else None
        estop = self.backend.estop_engaged()
        sim = self.backend.simulated
        cfg = self.config
        beam_stuck = (self._active is None and self._idle_blocked_since is not None
                      and time.monotonic_ns() - self._idle_blocked_since > cfg.beam.max_block_ms * MS)
        commissioned = {
            "coin": sim or cfg.coin.commissioned,
            "beam": sim or cfg.beam.commissioned,
            "compartments": sim or all(c.commissioned for c in cfg.compartments.values()),
        }
        oe_ok = probe["output_enable"] is True or not cfg.safety.require_output_enable
        connected = journal_ok and bool(probe["servo_driver"]) and bool(probe["coin_input"]) and bool(probe["beam_input"])
        health = {
            "service": "medivend-hwd",
            "version": __version__,
            "backend": cfg.backend,
            "simulated": sim,
            "connected": connected,
            "coin_events_verified": connected and commissioned["coin"] and counts["open_anomalies"] == 0 and self._last_error is None,
            "sensor_events_verified": connected and commissioned["beam"] and commissioned["compartments"] and not beam_stuck,
            "command_deduplication": journal_ok,
            "persistent_event_ids": journal_ok,
            "safe_stop": connected and oe_ok and estop is not True and lockout is None,
            "denominations_centavos": cfg.denominations(),
            "details": {
                "probe": probe,
                "commissioned": commissioned,
                "estop_engaged": estop,
                "lockout": lockout,
                "beam_blocked": self.backend.beam_blocked(),
                "beam_stuck": beam_stuck,
                "active_command": self._active,
                "last_error": self._last_error,
                "uptime_s": int(time.time() - self._started),
                # Longest possible dispense cycle; the PHP delivery timeout must exceed it.
                "max_cycle_ms": int(max(2 * c.move_ms + c.hold_ms for c in cfg.compartments.values())
                                    + cfg.beam.watch_after_return_ms + cfg.beam.max_block_ms + cfg.beam.settle_ms),
                **counts,
            },
        }
        health["ready"] = all(health[k] is True for k in (
            "connected", "coin_events_verified", "sensor_events_verified", "command_deduplication", "persistent_event_ids", "safe_stop"
        )) and health["denominations_centavos"] == REQUIRED_DENOMINATIONS
        return health

    # -- events ---------------------------------------------------------------
    def events(self, limit: int) -> list[dict[str, Any]]:
        return self.journal.unacked_events(max(1, min(limit, 200)))

    def acknowledge(self, event_id: str) -> None:
        if not HEX64.match(event_id):
            raise ServiceError(422, "invalid_event_id", "event_id must be 64 lowercase hex characters")
        if not self.journal.acknowledge(event_id):
            raise ServiceError(404, "event_not_found", "unknown event")

    # -- dispensing -----------------------------------------------------------
    def dispense(self, command_id: Any, compartment: Any, unit_number: Any) -> tuple[dict[str, Any], bool]:
        if not isinstance(command_id, str) or not HEX64.match(command_id):
            raise ServiceError(422, "invalid_command_id", "command_id must be 64 lowercase hex characters")
        if not isinstance(compartment, int) or isinstance(compartment, bool) or compartment not in self.config.compartments:
            raise ServiceError(422, "unknown_compartment", "compartment is not configured")
        if not isinstance(unit_number, int) or isinstance(unit_number, bool) or not 1 <= unit_number <= 65535:
            raise ServiceError(422, "invalid_unit", "unit_number must be 1-65535")
        with self._lock:
            existing = self.journal.command(command_id)
            if existing is not None:
                if existing["compartment"] != compartment or existing["unit_number"] != unit_number:
                    raise ServiceError(409, "command_conflict", "command ID reused with different parameters")
                return existing, False  # deduplicated: never actuate twice
            health = self.health()
            if not health["ready"]:
                raise ServiceError(423, "not_ready", "hardware is not ready; no motor was moved")
            if self._active is not None:
                raise ServiceError(409, "busy", "another command is in progress")
            try:
                command, _ = self.journal.claim_command(command_id, compartment, unit_number)
            except CommandConflict as error:
                raise ServiceError(409, "command_conflict", str(error)) from error
            self._active = command_id
            self._abort.clear()
            self._cycle = threading.Thread(target=self._run_cycle, args=(command_id, compartment), name=f"cycle-{command_id[:8]}", daemon=True)
            self._cycle.start()
            return command, True

    def command(self, command_id: str) -> dict[str, Any]:
        if not HEX64.match(command_id):
            raise ServiceError(422, "invalid_command_id", "command_id must be 64 lowercase hex characters")
        command = self.journal.command(command_id)
        if command is None:
            raise ServiceError(404, "command_not_found", "unknown command")
        return command

    def _wait(self, milliseconds: float) -> bool:
        """Interruptible sleep; returns False if aborted or the E-stop engaged."""
        deadline = time.monotonic() + milliseconds / 1000
        while True:
            remaining = deadline - time.monotonic()
            if self._abort.is_set() or self.backend.estop_engaged() is True:
                return False
            if remaining <= 0:
                return True
            self._abort.wait(min(remaining, 0.02))

    def _run_cycle(self, command_id: str, number: int) -> None:
        comp = self.config.compartments[number]
        servo, beam = self.config.servo, self.config.beam
        outcome, detail = "uncertain", "cycle did not complete"
        moved = False
        try:
            if self.backend.beam_blocked():
                outcome, detail = "jam", "beam already blocked before actuation; motor not moved"
                return
            window = BeamWindow(int(beam.debounce_ms * MS), int(beam.max_block_ms * MS), blocked_at_start=False)
            self._window = window
            self.journal.set_command_status(command_id, "actuating")
            rest = angle_to_pulse_us(comp.rest_angle, servo.min_pulse_us, servo.max_pulse_us)
            out = angle_to_pulse_us(comp.dispense_angle, servo.min_pulse_us, servo.max_pulse_us)
            self.backend.set_outputs_enabled(True)
            moved = True
            self.backend.set_servo_pulse(comp.channel, out)
            self.backend.dispense_started(number)
            completed = self._wait(comp.move_ms + comp.hold_ms)
            self.backend.set_servo_pulse(comp.channel, rest)
            completed = self._wait(comp.move_ms) and completed
            self.backend.set_servo_pulse(comp.channel, None)
            self.backend.set_outputs_enabled(False)
            if not completed:
                outcome, detail = "uncertain", "stopped during motor movement"
                return
            self.journal.set_command_status(command_id, "watching")
            deadline = time.monotonic_ns() + int(beam.watch_after_return_ms * MS)
            while time.monotonic_ns() < deadline or window.currently_blocked():
                now = time.monotonic_ns()
                if window.check_jam(now):
                    break
                if window.passages == 1 and not window.currently_blocked() and window.first_clear_ns is not None \
                        and now - window.first_clear_ns >= beam.settle_ms * MS:
                    break
                if not self._wait(10):
                    outcome, detail = "uncertain", "stopped while watching the beam"
                    return
            outcome, detail = window.outcome()
        except Exception as error:
            outcome, detail = "uncertain", f"cycle error: {error}"
            self._last_error = f"cycle: {error}"
            log.exception("dispense cycle failed command=%s", command_id)
        finally:
            if moved:
                try:
                    self.backend.set_servo_pulse(comp.channel, None)
                    self.backend.all_off()
                except Exception:
                    log.exception("failed to de-energise outputs")
                    self._last_error = "failed to de-energise outputs"
            self._window = None
            event = self.journal.finish_command(command_id, outcome, detail)
            with self._lock:
                self._active = None
            log.info("dispense finished command=%s compartment=%s outcome=%s detail=%s event=%s",
                     command_id, number, outcome, detail, event and event["event_id"])

    # -- safety ---------------------------------------------------------------
    def stop(self, reason: str = "stop requested") -> dict[str, Any]:
        self._abort.set()
        try:
            self.backend.all_off()
        except Exception as error:
            self._last_error = f"stop: {error}"
            raise ServiceError(503, "stop_uncertain", "outputs could not be confirmed off") from error
        # The PHP worker calls stop() on every tick while not ready; only an
        # interrupted command is worth a warning.
        (log.warning if self._active else log.debug)("stop: %s (active=%s)", reason, self._active)
        return {"stopped": True, "active_command": self._active}

    def set_lockout(self, engaged: bool, note: str) -> dict[str, Any]:
        if engaged:
            self.stop("maintenance lockout")
            self.journal.set_meta("lockout", f"{int(time.time())}: {note}")
        else:
            self.journal.delete_meta("lockout")
        log.warning("lockout %s: %s", "engaged" if engaged else "cleared", note)
        return {"lockout": self.journal.meta("lockout")}

    def clear_anomaly(self, anomaly_id: Any, note: str) -> None:
        if not isinstance(anomaly_id, int) or isinstance(anomaly_id, bool):
            raise ServiceError(422, "invalid_anomaly", "anomaly id must be an integer")
        if not self.journal.clear_anomaly(anomaly_id, note):
            raise ServiceError(404, "anomaly_not_found", "anomaly not found or already cleared")
        log.warning("anomaly #%s cleared: %s", anomaly_id, note)

    def servo_test(self, compartment: Any, angle: Any, hold_ms: Any) -> dict[str, Any]:
        """Bench calibration move. Only while the maintenance lockout is engaged."""
        if self.journal.meta("lockout") is None:
            raise ServiceError(423, "lockout_required", "engage the maintenance lockout before servo tests")
        if self.backend.estop_engaged() is True:
            raise ServiceError(423, "estop_engaged", "E-stop is engaged")
        if not isinstance(compartment, int) or compartment not in self.config.compartments:
            raise ServiceError(422, "unknown_compartment", "compartment is not configured")
        if not isinstance(angle, (int, float)) or isinstance(angle, bool) or not 0 <= angle <= 180:
            raise ServiceError(422, "invalid_angle", "angle must be within 0-180 degrees")
        if not isinstance(hold_ms, int) or not 100 <= hold_ms <= 5000:
            raise ServiceError(422, "invalid_hold", "hold_ms must be 100-5000")
        with self._lock:
            if self._active is not None:
                raise ServiceError(409, "busy", "a command is in progress")
            comp = self.config.compartments[compartment]
            pulse = angle_to_pulse_us(float(angle), self.config.servo.min_pulse_us, self.config.servo.max_pulse_us)
            self._abort.clear()
            try:
                self.backend.set_outputs_enabled(True)
                self.backend.set_servo_pulse(comp.channel, pulse)
                self._wait(hold_ms)
            finally:
                self.backend.set_servo_pulse(comp.channel, None)
                self.backend.all_off()
        log.info("servo test compartment=%s channel=%s angle=%s pulse_us=%.0f", compartment, comp.channel, angle, pulse)
        return {"compartment": compartment, "channel": comp.channel, "angle": angle, "pulse_us": round(pulse)}

    # -- simulation -----------------------------------------------------------
    def sim(self) -> SimBackend:
        if not isinstance(self.backend, SimBackend):
            raise ServiceError(404, "not_simulated", "simulation endpoints exist only with backend = 'sim'")
        return self.backend
