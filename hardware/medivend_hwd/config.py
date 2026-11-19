"""Configuration loading and validation (TOML, Python 3.11+ tomllib).

Nothing hardware-specific has a default: GPIO line offsets, servo angles and
pulse mappings must come from the bench-verified configuration file.
"""
from __future__ import annotations

import tomllib
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Optional

REQUIRED_DENOMINATIONS = [500, 1000, 2000]  # ₱5, ₱10, ₱20 (₱1 not accepted)


class ConfigError(ValueError):
    pass


@dataclass(frozen=True)
class LineConfig:
    chip: str
    line: int
    active_low: bool
    bias: str = "as-is"  # as-is | disabled | pull-up | pull-down


@dataclass(frozen=True)
class CoinConfig:
    commissioned: bool
    pulses: dict[int, int]  # pulse count -> centavos
    glitch_ms: float
    pulse_min_ms: float
    pulse_max_ms: float
    train_gap_ms: float
    input: Optional[LineConfig]


@dataclass(frozen=True)
class BeamConfig:
    commissioned: bool
    debounce_ms: float
    max_block_ms: float
    watch_after_return_ms: float
    settle_ms: float
    input: Optional[LineConfig]


@dataclass(frozen=True)
class ServoConfig:
    i2c_bus: int
    address: int
    frequency_hz: float
    oscillator_hz: float
    min_pulse_us: float
    max_pulse_us: float


@dataclass(frozen=True)
class SafetyConfig:
    require_output_enable: bool
    output_enable: Optional[LineConfig]  # PCA9685 /OE: active level = outputs ENABLED
    estop: Optional[LineConfig]          # active level = E-stop ENGAGED


@dataclass(frozen=True)
class CompartmentConfig:
    number: int
    channel: int
    rest_angle: float
    dispense_angle: float
    move_ms: float
    hold_ms: float
    commissioned: bool


@dataclass(frozen=True)
class SimConfig:
    pulse_ms: float = 30.0
    pulse_gap_ms: float = 60.0
    beam_delay_ms: float = 150.0
    beam_block_ms: float = 40.0
    default_beam: str = "pass"


@dataclass(frozen=True)
class Config:
    host: str
    port: int
    token_file: str
    journal_path: str
    backend: str  # sim | coin_test (real coin slot, simulated servos/beam) | real
    coin: CoinConfig
    beam: BeamConfig
    servo: ServoConfig
    safety: SafetyConfig
    compartments: dict[int, CompartmentConfig]
    sim: SimConfig = field(default_factory=SimConfig)

    @property
    def simulated(self) -> bool:
        return self.backend != "real"

    def denominations(self) -> list[int]:
        return sorted(self.coin.pulses.values())


def _get(table: dict[str, Any], key: str, kind: type | tuple[type, ...], where: str, default: Any = ...) -> Any:
    if key not in table:
        if default is ...:
            raise ConfigError(f"{where}.{key} is required")
        return default
    value = table[key]
    if kind is float and isinstance(value, int) and not isinstance(value, bool):
        value = float(value)
    if isinstance(value, bool) and kind in (int, float):
        raise ConfigError(f"{where}.{key} must be a number")
    if not isinstance(value, kind):
        raise ConfigError(f"{where}.{key} has the wrong type")
    return value


def _positive(value: float, name: str) -> float:
    if value <= 0:
        raise ConfigError(f"{name} must be positive")
    return value


def _line(table: Optional[dict[str, Any]], where: str, required: bool) -> Optional[LineConfig]:
    if not table or "line" not in table:
        if required:
            raise ConfigError(f"{where}.line is required for the real backend (verify the wiring; no pin is assumed)")
        return None
    line = _get(table, "line", int, where)
    if line < 0:
        raise ConfigError(f"{where}.line must be a non-negative line offset")
    bias = _get(table, "bias", str, where, "as-is")
    if bias not in ("as-is", "disabled", "pull-up", "pull-down"):
        raise ConfigError(f"{where}.bias must be as-is, disabled, pull-up or pull-down")
    return LineConfig(
        chip=_get(table, "chip", str, where, "/dev/gpiochip0"),
        line=line,
        active_low=_get(table, "active_low", bool, where),
        bias=bias,
    )


def parse(data: dict[str, Any]) -> Config:
    service = data.get("service", {})
    backend = _get(service, "backend", str, "service")
    if backend not in ("sim", "coin_test", "real"):
        raise ConfigError("service.backend must be 'sim', 'coin_test' or 'real'")
    real = backend == "real"
    host = _get(service, "host", str, "service", "127.0.0.1")
    if host not in ("127.0.0.1", "::1", "localhost"):
        raise ConfigError("service.host must be a loopback address")
    port = _get(service, "port", int, "service", 8765)
    if not 1024 <= port <= 65535:
        raise ConfigError("service.port must be between 1024 and 65535")

    coin_t = data.get("coin", {})
    raw_pulses = _get(coin_t, "pulses", dict, "coin")
    pulses: dict[int, int] = {}
    for count, centavos in raw_pulses.items():
        try:
            n = int(count)
        except ValueError as error:
            raise ConfigError("coin.pulses keys must be pulse counts") from error
        if n < 1 or n > 100 or not isinstance(centavos, int) or isinstance(centavos, bool):
            raise ConfigError("coin.pulses must map 1..100 pulses to integer centavos")
        pulses[n] = centavos
    if sorted(pulses.values()) != REQUIRED_DENOMINATIONS:
        raise ConfigError(f"coin.pulses must map exactly the denominations {REQUIRED_DENOMINATIONS}")
    coin = CoinConfig(
        commissioned=_get(coin_t, "commissioned", bool, "coin", False),
        pulses=pulses,
        glitch_ms=_positive(_get(coin_t, "glitch_ms", float, "coin", 5.0), "coin.glitch_ms"),
        pulse_min_ms=_positive(_get(coin_t, "pulse_min_ms", float, "coin"), "coin.pulse_min_ms"),
        pulse_max_ms=_positive(_get(coin_t, "pulse_max_ms", float, "coin"), "coin.pulse_max_ms"),
        train_gap_ms=_positive(_get(coin_t, "train_gap_ms", float, "coin"), "coin.train_gap_ms"),
        input=_line(coin_t.get("input"), "coin.input", real or backend == "coin_test"),
    )
    if not coin.glitch_ms < coin.pulse_min_ms < coin.pulse_max_ms < coin.train_gap_ms:
        raise ConfigError("coin timings must satisfy glitch_ms < pulse_min_ms < pulse_max_ms < train_gap_ms")

    beam_t = data.get("beam", {})
    beam = BeamConfig(
        commissioned=_get(beam_t, "commissioned", bool, "beam", False),
        debounce_ms=_positive(_get(beam_t, "debounce_ms", float, "beam", 2.0), "beam.debounce_ms"),
        max_block_ms=_positive(_get(beam_t, "max_block_ms", float, "beam"), "beam.max_block_ms"),
        watch_after_return_ms=_positive(_get(beam_t, "watch_after_return_ms", float, "beam"), "beam.watch_after_return_ms"),
        settle_ms=_positive(_get(beam_t, "settle_ms", float, "beam", 300.0), "beam.settle_ms"),
        input=_line(beam_t.get("input"), "beam.input", real),
    )

    servo_t = data.get("servo", {})
    servo = ServoConfig(
        i2c_bus=_get(servo_t, "i2c_bus", int, "servo", 0),
        address=_get(servo_t, "address", int, "servo", 0x40),
        frequency_hz=_positive(_get(servo_t, "frequency_hz", float, "servo", 50.0), "servo.frequency_hz"),
        oscillator_hz=_positive(_get(servo_t, "oscillator_hz", float, "servo", 25_000_000.0), "servo.oscillator_hz"),
        min_pulse_us=_positive(_get(servo_t, "min_pulse_us", float, "servo"), "servo.min_pulse_us"),
        max_pulse_us=_positive(_get(servo_t, "max_pulse_us", float, "servo"), "servo.max_pulse_us"),
    )
    if not 0x03 <= servo.address <= 0x77:
        raise ConfigError("servo.address must be a 7-bit I2C address")
    if not 24 <= servo.frequency_hz <= 1526:
        raise ConfigError("servo.frequency_hz is outside the PCA9685 range (24-1526 Hz)")
    if not 400 <= servo.min_pulse_us < servo.max_pulse_us <= 2600:
        raise ConfigError("servo pulse range must satisfy 400 <= min_pulse_us < max_pulse_us <= 2600")

    safety_t = data.get("safety", {})
    safety = SafetyConfig(
        require_output_enable=_get(safety_t, "require_output_enable", bool, "safety", True),
        output_enable=_line(safety_t.get("output_enable"), "safety.output_enable", False),
        estop=_line(safety_t.get("estop"), "safety.estop", False),
    )

    compartments: dict[int, CompartmentConfig] = {}
    channels: set[int] = set()
    for index, entry in enumerate(data.get("compartments", [])):
        where = f"compartments[{index}]"
        comp = CompartmentConfig(
            number=_get(entry, "number", int, where),
            channel=_get(entry, "channel", int, where),
            rest_angle=_get(entry, "rest_angle", float, where),
            dispense_angle=_get(entry, "dispense_angle", float, where),
            move_ms=_positive(_get(entry, "move_ms", float, where), f"{where}.move_ms"),
            hold_ms=_get(entry, "hold_ms", float, where, 0.0),
            commissioned=_get(entry, "commissioned", bool, where, False),
        )
        if comp.number < 1 or comp.number in compartments:
            raise ConfigError(f"{where}.number must be a unique positive compartment number")
        if not 0 <= comp.channel <= 15 or comp.channel in channels:
            raise ConfigError(f"{where}.channel must be a unique PCA9685 channel 0-15")
        for name in ("rest_angle", "dispense_angle"):
            if not 0 <= getattr(comp, name) <= 180:
                raise ConfigError(f"{where}.{name} must be within 0-180 degrees")
        if comp.hold_ms < 0:
            raise ConfigError(f"{where}.hold_ms must not be negative")
        compartments[comp.number] = comp
        channels.add(comp.channel)
    if not compartments:
        raise ConfigError("at least one [[compartments]] entry is required")

    sim_t = data.get("sim", {})
    sim = SimConfig(
        pulse_ms=_get(sim_t, "pulse_ms", float, "sim", 30.0),
        pulse_gap_ms=_get(sim_t, "pulse_gap_ms", float, "sim", 60.0),
        beam_delay_ms=_get(sim_t, "beam_delay_ms", float, "sim", 150.0),
        beam_block_ms=_get(sim_t, "beam_block_ms", float, "sim", 40.0),
        default_beam=_get(sim_t, "default_beam", str, "sim", "pass"),
    )

    return Config(
        host=host,
        port=port,
        token_file=_get(service, "token_file", str, "service"),
        journal_path=_get(service, "journal_path", str, "service"),
        backend=backend,
        coin=coin,
        beam=beam,
        servo=servo,
        safety=safety,
        compartments=compartments,
        sim=sim,
    )


def load(path: str | Path) -> Config:
    with open(path, "rb") as handle:
        return parse(tomllib.load(handle))


def read_token(path: str) -> str:
    token = Path(path).read_text(encoding="ascii").strip()
    if len(token) < 32:
        raise ConfigError("service token must be at least 32 characters")
    return token
