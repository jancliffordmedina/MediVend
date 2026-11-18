"""Minimal PCA9685 driver over smbus2 (no Blinka/Adafruit stack needed).

Wiring assumptions that MUST be verified on the bench, not by this code:
  * VCC (logic) at 3.3 V so the breakout's I2C pull-ups stay at 3.3 V.
  * V+ (servo power) from a separate buck converter; common ground.
  * /OE pulled high (outputs disabled) by default and driven by a GPIO.
"""
from __future__ import annotations

import time
from typing import Any, Optional, Protocol

MODE1 = 0x00
MODE2 = 0x01
PRESCALE = 0xFE
LED0_ON_L = 0x06
ALL_LED_OFF_H = 0xFD
MODE1_RESTART = 0x80
MODE1_AI = 0x20
MODE1_SLEEP = 0x10
MODE2_OUTDRV = 0x04
FULL_OFF = 0x10


class Bus(Protocol):
    def read_byte_data(self, address: int, register: int) -> int: ...
    def write_byte_data(self, address: int, register: int, value: int) -> None: ...
    def write_i2c_block_data(self, address: int, register: int, data: list[int]) -> None: ...


def prescale_for(oscillator_hz: float, frequency_hz: float) -> int:
    value = round(oscillator_hz / (4096 * frequency_hz)) - 1
    if not 3 <= value <= 255:
        raise ValueError("frequency outside PCA9685 prescale range")
    return value


def actual_frequency(oscillator_hz: float, prescale: int) -> float:
    return oscillator_hz / (4096 * (prescale + 1))


def ticks_for(pulse_us: float, frequency_hz: float) -> int:
    ticks = round(pulse_us * frequency_hz * 4096 / 1_000_000)
    if not 0 < ticks < 4096:
        raise ValueError("pulse width outside one PWM period")
    return ticks


def angle_to_pulse_us(angle: float, min_us: float, max_us: float) -> float:
    if not 0 <= angle <= 180:
        raise ValueError("angle must be within 0-180 degrees")
    return min_us + (max_us - min_us) * angle / 180.0


class PCA9685:
    def __init__(self, bus: Bus, address: int, frequency_hz: float, oscillator_hz: float):
        self.bus = bus
        self.address = address
        self.prescale = prescale_for(oscillator_hz, frequency_hz)
        self.frequency = actual_frequency(oscillator_hz, self.prescale)

    @classmethod
    def open(cls, bus_number: int, address: int, frequency_hz: float, oscillator_hz: float) -> "PCA9685":
        try:  # imported lazily: only the real backend needs it
            from smbus2 import SMBus
        except ImportError:  # Debian's python3-smbus exposes the same methods used here
            from smbus import SMBus  # type: ignore[no-redef]

        return cls(SMBus(bus_number), address, frequency_hz, oscillator_hz)

    def initialise(self) -> None:
        self.all_off()
        self.bus.write_byte_data(self.address, MODE2, MODE2_OUTDRV)
        old = self.bus.read_byte_data(self.address, MODE1) & ~MODE1_RESTART
        self.bus.write_byte_data(self.address, MODE1, (old & 0x7F) | MODE1_SLEEP)
        self.bus.write_byte_data(self.address, PRESCALE, self.prescale)
        self.bus.write_byte_data(self.address, MODE1, (old & ~MODE1_SLEEP) | MODE1_AI)
        time.sleep(0.005)  # oscillator start-up (datasheet: 500 us)
        self.bus.write_byte_data(self.address, MODE1, (old & ~MODE1_SLEEP) | MODE1_AI | MODE1_RESTART)

    def probe(self) -> bool:
        try:
            return self.bus.read_byte_data(self.address, PRESCALE) == self.prescale
        except OSError:
            return False

    def set_pulse_us(self, channel: int, pulse_us: Optional[float]) -> None:
        if not 0 <= channel <= 15:
            raise ValueError("channel must be 0-15")
        if pulse_us is None:
            data = [0, 0, 0, FULL_OFF]
        else:
            ticks = ticks_for(pulse_us, self.frequency)
            data = [0, 0, ticks & 0xFF, ticks >> 8]
        self.bus.write_i2c_block_data(self.address, LED0_ON_L + 4 * channel, data)

    def all_off(self) -> None:
        self.bus.write_byte_data(self.address, ALL_LED_OFF_H, FULL_OFF)

    def close(self) -> None:
        closer: Any = getattr(self.bus, "close", None)
        if closer:
            closer()
