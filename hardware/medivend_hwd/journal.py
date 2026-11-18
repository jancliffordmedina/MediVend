"""Durable SQLite journal: observations, command deduplication, anomalies.

Every write that reports something to PHP (a coin, a delivery outcome) commits
with synchronous=FULL before it is visible over the API, so an acknowledged-
later event survives process or power loss. Command IDs are persisted before
any motor output, so a command is never actuated twice, even across restarts.
"""
from __future__ import annotations

import json
import secrets
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any, Optional

FINAL_STATUSES = ("sensor_confirmed", "jam", "timeout", "uncertain")
IN_FLIGHT_STATUSES = ("received", "actuating", "watching")

SCHEMA = """
CREATE TABLE IF NOT EXISTS events (
    seq INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL CHECK (type IN ('accepted_coin', 'delivery')),
    payload TEXT NOT NULL,
    observed_at INTEGER NOT NULL,
    acked_at INTEGER
);
CREATE INDEX IF NOT EXISTS idx_events_unacked ON events (acked_at, seq);
CREATE TABLE IF NOT EXISTS commands (
    command_id TEXT PRIMARY KEY,
    compartment INTEGER NOT NULL,
    unit_number INTEGER NOT NULL,
    status TEXT NOT NULL,
    detail TEXT,
    event_id TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS anomalies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    detail TEXT NOT NULL,
    observed_at INTEGER NOT NULL,
    cleared_at INTEGER,
    cleared_note TEXT
);
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
"""


class JournalError(RuntimeError):
    pass


class CommandConflict(JournalError):
    pass


class Journal:
    def __init__(self, path: str):
        if path != ":memory:":
            Path(path).parent.mkdir(parents=True, exist_ok=True)
        self._lock = threading.RLock()
        self._db = sqlite3.connect(path, check_same_thread=False, isolation_level=None)
        self._db.row_factory = sqlite3.Row
        self._db.execute("PRAGMA journal_mode=WAL")
        self._db.execute("PRAGMA synchronous=FULL")
        self._db.execute("PRAGMA foreign_keys=ON")
        self._db.executescript(SCHEMA)
        if self.meta("device_id") is None:
            self.set_meta("device_id", secrets.token_hex(16))

    def close(self) -> None:
        with self._lock:
            self._db.close()

    def healthy(self) -> bool:
        try:
            with self._lock:
                return self._db.execute("PRAGMA quick_check").fetchone()[0] == "ok"
        except sqlite3.Error:
            return False

    # -- meta -----------------------------------------------------------------
    def meta(self, key: str) -> Optional[str]:
        with self._lock:
            row = self._db.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
            return None if row is None else row["value"]

    def set_meta(self, key: str, value: str) -> None:
        with self._lock:
            self._db.execute("INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", (key, value))

    def delete_meta(self, key: str) -> None:
        with self._lock:
            self._db.execute("DELETE FROM meta WHERE key = ?", (key,))

    # -- events ---------------------------------------------------------------
    def record_coin(self, centavos: int, observed_at: Optional[int] = None) -> dict[str, Any]:
        observed = int(observed_at if observed_at is not None else time.time())
        event_id = secrets.token_hex(32)
        payload = {"type": "accepted_coin", "event_id": event_id, "coin_centavos": centavos, "observed_at": observed}
        with self._lock:
            self._db.execute(
                "INSERT INTO events (event_id, type, payload, observed_at) VALUES (?, 'accepted_coin', ?, ?)",
                (event_id, json.dumps(payload), observed),
            )
        return payload

    def unacked_events(self, limit: int = 50) -> list[dict[str, Any]]:
        with self._lock:
            rows = self._db.execute(
                "SELECT payload FROM events WHERE acked_at IS NULL ORDER BY seq LIMIT ?", (limit,)
            ).fetchall()
        return [json.loads(row["payload"]) for row in rows]

    def acknowledge(self, event_id: str) -> bool:
        with self._lock:
            row = self._db.execute("SELECT acked_at FROM events WHERE event_id = ?", (event_id,)).fetchone()
            if row is None:
                return False
            if row["acked_at"] is None:
                self._db.execute("UPDATE events SET acked_at = ? WHERE event_id = ?", (int(time.time()), event_id))
            return True

    def counts(self) -> dict[str, int]:
        with self._lock:
            unacked = self._db.execute("SELECT COUNT(*) FROM events WHERE acked_at IS NULL").fetchone()[0]
            anomalies = self._db.execute("SELECT COUNT(*) FROM anomalies WHERE cleared_at IS NULL").fetchone()[0]
        return {"unacknowledged_events": unacked, "open_anomalies": anomalies}

    # -- commands -------------------------------------------------------------
    def claim_command(self, command_id: str, compartment: int, unit_number: int) -> tuple[dict[str, Any], bool]:
        """Persist a command before actuation. Returns (command, is_new)."""
        now = int(time.time())
        with self._lock:
            existing = self.command(command_id)
            if existing is not None:
                if existing["compartment"] != compartment or existing["unit_number"] != unit_number:
                    raise CommandConflict("command ID reused with different parameters")
                return existing, False
            self._db.execute(
                "INSERT INTO commands (command_id, compartment, unit_number, status, created_at, updated_at) VALUES (?, ?, ?, 'received', ?, ?)",
                (command_id, compartment, unit_number, now, now),
            )
            return self.command(command_id), True  # type: ignore[return-value]

    def command(self, command_id: str) -> Optional[dict[str, Any]]:
        with self._lock:
            row = self._db.execute("SELECT * FROM commands WHERE command_id = ?", (command_id,)).fetchone()
        return None if row is None else dict(row)

    def set_command_status(self, command_id: str, status: str, detail: Optional[str] = None) -> None:
        if status not in IN_FLIGHT_STATUSES:
            raise JournalError("use finish_command for final outcomes")
        with self._lock:
            self._db.execute(
                "UPDATE commands SET status = ?, detail = COALESCE(?, detail), updated_at = ? WHERE command_id = ? AND status IN ('received', 'actuating', 'watching')",
                (status, detail, int(time.time()), command_id),
            )

    def finish_command(self, command_id: str, outcome: str, detail: str) -> Optional[dict[str, Any]]:
        """Atomically record the final outcome and its delivery event (once)."""
        if outcome not in FINAL_STATUSES:
            raise JournalError(f"invalid outcome {outcome}")
        now = int(time.time())
        event_id = secrets.token_hex(32)
        payload = {"type": "delivery", "event_id": event_id, "command_id": command_id, "outcome": outcome, "observed_at": now}
        with self._lock:
            self._db.execute("BEGIN IMMEDIATE")
            try:
                changed = self._db.execute(
                    "UPDATE commands SET status = ?, detail = ?, event_id = ?, updated_at = ? WHERE command_id = ? AND status IN ('received', 'actuating', 'watching')",
                    (outcome, detail, event_id, now, command_id),
                ).rowcount
                if changed != 1:
                    self._db.execute("ROLLBACK")
                    return None
                self._db.execute(
                    "INSERT INTO events (event_id, type, payload, observed_at) VALUES (?, 'delivery', ?, ?)",
                    (event_id, json.dumps(payload), now),
                )
                self._db.execute("COMMIT")
            except BaseException:
                self._db.execute("ROLLBACK")
                raise
        return payload

    def in_flight_commands(self) -> list[dict[str, Any]]:
        with self._lock:
            rows = self._db.execute(
                "SELECT * FROM commands WHERE status IN ('received', 'actuating', 'watching') ORDER BY created_at"
            ).fetchall()
        return [dict(row) for row in rows]

    # -- anomalies ------------------------------------------------------------
    def record_anomaly(self, kind: str, detail: str) -> int:
        with self._lock:
            cursor = self._db.execute(
                "INSERT INTO anomalies (kind, detail, observed_at) VALUES (?, ?, ?)", (kind, detail, int(time.time()))
            )
            return int(cursor.lastrowid)

    def anomalies(self, include_cleared: bool = False) -> list[dict[str, Any]]:
        query = "SELECT * FROM anomalies" + ("" if include_cleared else " WHERE cleared_at IS NULL") + " ORDER BY id DESC LIMIT 100"
        with self._lock:
            return [dict(row) for row in self._db.execute(query).fetchall()]

    def clear_anomaly(self, anomaly_id: int, note: str) -> bool:
        with self._lock:
            return self._db.execute(
                "UPDATE anomalies SET cleared_at = ?, cleared_note = ? WHERE id = ? AND cleared_at IS NULL",
                (int(time.time()), note, anomaly_id),
            ).rowcount == 1
