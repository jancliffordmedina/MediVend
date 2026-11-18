"""Pure signal logic: coin pulse-train decoding and break-beam evaluation.

Inputs are (active, timestamp_ns) edges where `active` is already translated
from the electrical level (active-low handling happens in the backend).
Edge timestamps measure widths; a separate monotonic `now_ns` detects the end
of a pulse train, so kernel event clocks never mix with wall-clock time.
"""
from __future__ import annotations

import threading
from dataclasses import dataclass, field
from typing import Optional

MS = 1_000_000


@dataclass
class CoinResult:
    centavos: Optional[int]
    pulses: int
    anomaly: Optional[str] = None  # set when the train cannot be trusted


class CoinDecoder:
    def __init__(self, pulses: dict[int, int], glitch_ms: float, pulse_min_ms: float, pulse_max_ms: float, train_gap_ms: float):
        self._map = dict(pulses)
        self._glitch = glitch_ms * MS
        self._min = pulse_min_ms * MS
        self._max = pulse_max_ms * MS
        self._gap = train_gap_ms * MS
        self._lock = threading.Lock()
        self._reset()
        self.ignored_glitches = 0

    def _reset(self) -> None:
        self._count = 0
        self._bad_width: Optional[float] = None
        self._active_since: Optional[int] = None
        self._last_activity_mono: Optional[int] = None

    def edge(self, active: bool, ts_ns: int, mono_ns: int) -> None:
        with self._lock:
            if active:
                if self._active_since is None:
                    self._active_since = ts_ns
                    self._last_activity_mono = mono_ns
                return
            if self._active_since is None:
                return  # release without a start (e.g. service started mid-pulse)
            width = ts_ns - self._active_since
            self._active_since = None
            if width < self._glitch:
                self.ignored_glitches += 1
                return
            self._count += 1
            self._last_activity_mono = mono_ns
            if not self._min <= width <= self._max and self._bad_width is None:
                self._bad_width = width / MS

    def poll(self, now_ns: int) -> Optional[CoinResult]:
        """Return a completed train once no pulse has been seen for train_gap_ms."""
        with self._lock:
            if self._active_since is not None:
                # Held active far longer than any valid pulse: a stuck line.
                if self._last_activity_mono is not None and now_ns - self._last_activity_mono > self._gap + self._max:
                    count = self._count
                    self._reset()
                    return CoinResult(None, count, "coin line held active")
                return None
            if self._count == 0 or self._last_activity_mono is None or now_ns - self._last_activity_mono < self._gap:
                return None
            count, bad = self._count, self._bad_width
            self._reset()
        if bad is not None:
            return CoinResult(None, count, f"pulse width {bad:.1f} ms outside configured range")
        value = self._map.get(count)
        if value is None:
            return CoinResult(None, count, f"unknown pulse count {count}")
        return CoinResult(value, count)

    @property
    def idle(self) -> bool:
        with self._lock:
            return self._count == 0 and self._active_since is None


@dataclass
class BeamWindow:
    """Observes the shared chute beam during one dispensing cycle."""

    debounce_ns: int
    max_block_ns: int
    blocked_at_start: bool
    passages: int = 0
    blocked_since: Optional[int] = None
    first_clear_ns: Optional[int] = None
    glitches: int = 0
    jammed: bool = False
    _lock: threading.Lock = field(default_factory=threading.Lock, repr=False)

    def edge(self, blocked: bool, ts_ns: int, mono_ns: int) -> None:
        with self._lock:
            if blocked:
                if self.blocked_since is None:
                    self.blocked_since = mono_ns
                return
            if self.blocked_since is None:
                return
            duration = mono_ns - self.blocked_since
            self.blocked_since = None
            if duration < self.debounce_ns:
                self.glitches += 1
                return
            if duration > self.max_block_ns:
                self.jammed = True
            self.passages += 1
            if self.first_clear_ns is None:
                self.first_clear_ns = mono_ns

    def check_jam(self, now_ns: int) -> bool:
        with self._lock:
            if self.blocked_since is not None and now_ns - self.blocked_since > self.max_block_ns:
                self.jammed = True
            return self.jammed

    def currently_blocked(self) -> bool:
        with self._lock:
            return self.blocked_since is not None

    def outcome(self) -> tuple[str, str]:
        with self._lock:
            if self.jammed:
                return "jam", f"beam blocked longer than {self.max_block_ns / MS:.0f} ms"
            if self.blocked_since is not None:
                return "uncertain", "beam still blocked when the window closed"
            if self.passages == 1:
                return "sensor_confirmed", "one beam passage observed"
            if self.passages == 0:
                return "timeout", "no beam passage observed"
            return "uncertain", f"{self.passages} beam passages observed for one unit"
