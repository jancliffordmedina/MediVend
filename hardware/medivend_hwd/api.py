"""Local REST API (stdlib http.server; loopback + bearer token).

Envelope: {"ok": true, "data": {...}} or {"ok": false, "error": {"code", "message"}}.

  GET  /v1/health
  GET  /v1/events?limit=50              unacknowledged observations, oldest first
  POST /v1/events/{event_id}/ack        idempotent
  POST /v1/dispense                     {command_id, compartment, unit_number}  202 new / 200 replay
  GET  /v1/dispense/{command_id}
  POST /v1/stop                         {reason?}  abort motion, outputs off (not latched)
  POST /v1/lockout                      {engaged: bool, note}  latched maintenance lockout
  GET  /v1/anomalies
  POST /v1/anomalies/{id}/clear         {note}
  POST /v1/maintenance/servo            {compartment, angle, hold_ms}  lockout required
  POST /v1/sim/coin                     {centavos} | {pulses, pulse_ms?}   simulator only
  POST /v1/sim/beam                     {scenario: pass|none|jam|double|late}  simulator only
  POST /v1/sim/estop                    {engaged: bool}  simulator only
"""
from __future__ import annotations

import hmac
import ipaddress
import json
import logging
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Optional
from urllib.parse import parse_qs, urlsplit

from .service import HardwareService, ServiceError

log = logging.getLogger("medivend_hwd.api")
MAX_BODY = 4096
NOTE = re.compile(r"\A[^\x00-\x1f\x7f]{3,240}\Z")


def _note(body: dict[str, Any]) -> str:
    note = body.get("note")
    if not isinstance(note, str) or not NOTE.match(note):
        raise ServiceError(422, "invalid_note", "note must be 3-240 characters without control characters")
    return note


def make_handler(service: HardwareService, token: str) -> type[BaseHTTPRequestHandler]:
    expected = f"Bearer {token}".encode()

    class Handler(BaseHTTPRequestHandler):
        server_version = "medivend-hwd"
        sys_version = ""
        protocol_version = "HTTP/1.1"

        def log_message(self, fmt: str, *args: Any) -> None:  # route to logging, not stderr
            log.debug("%s %s", self.address_string(), fmt % args)

        def _send(self, status: int, payload: dict[str, Any]) -> None:
            body = json.dumps(payload, separators=(",", ":")).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("Content-Length", str(len(body)))
            if status >= 400:  # an unread request body must not be parsed as a new request
                self.send_header("Connection", "close")
                self.close_connection = True
            self.end_headers()
            self.wfile.write(body)

        def _ok(self, data: Any, status: int = 200) -> None:
            self._send(status, {"ok": True, "data": data})

        def _error(self, status: int, code: str, message: str) -> None:
            self._send(status, {"ok": False, "error": {"code": code, "message": message}})

        def _body(self) -> dict[str, Any]:
            length = self.headers.get("Content-Length")
            if length is None or not length.isdigit():
                raise ServiceError(411, "length_required", "Content-Length is required")
            if int(length) > MAX_BODY:
                raise ServiceError(413, "body_too_large", "request body too large")
            if (self.headers.get("Content-Type") or "").split(";")[0].strip() != "application/json":
                raise ServiceError(415, "json_required", "Content-Type must be application/json")
            raw = self.rfile.read(int(length)) if int(length) else b"{}"
            try:
                value = json.loads(raw)
            except (UnicodeDecodeError, json.JSONDecodeError) as error:
                raise ServiceError(400, "invalid_json", "malformed JSON") from error
            if not isinstance(value, dict):
                raise ServiceError(400, "invalid_json", "JSON body must be an object")
            return value

        def _authorised(self) -> bool:
            try:
                if not ipaddress.ip_address(self.client_address[0]).is_loopback:
                    return False
            except ValueError:
                return False
            supplied = (self.headers.get("Authorization") or "").encode()
            return hmac.compare_digest(supplied, expected)

        def _dispatch(self, method: str) -> None:
            try:
                if not self._authorised():
                    raise ServiceError(401, "unauthorised", "valid bearer token required")
                url = urlsplit(self.path)
                parts = [p for p in url.path.split("/") if p]
                if parts[:1] != ["v1"]:
                    raise ServiceError(404, "not_found", "unknown route")
                route = parts[1:]
                body = self._body() if method == "POST" else {}
                result = self._route(method, route, body, parse_qs(url.query))
                if result is None:
                    raise ServiceError(404, "not_found", "unknown route")
                status, data = result
                self._ok(data, status)
            except ServiceError as error:
                self._error(error.status, error.code, error.message)
            except Exception:
                log.exception("unhandled API error")
                self._error(500, "internal_error", "hardware service error; see service log")

        def _route(self, method: str, route: list[str], body: dict[str, Any], query: dict[str, list[str]]) -> Optional[tuple[int, Any]]:
            s = service
            if method == "GET" and route == ["health"]:
                return 200, s.health()
            if method == "GET" and route == ["events"]:
                raw = query.get("limit", ["50"])[0]
                if not raw.isdigit():
                    raise ServiceError(422, "invalid_limit", "limit must be an integer")
                return 200, {"events": s.events(int(raw))}
            if method == "POST" and len(route) == 3 and route[0] == "events" and route[2] == "ack":
                s.acknowledge(route[1])
                return 200, {"acknowledged": route[1]}
            if method == "POST" and route == ["dispense"]:
                unknown = set(body) - {"command_id", "compartment", "unit_number"}
                if unknown:
                    raise ServiceError(422, "unknown_fields", "unexpected fields: " + ", ".join(sorted(unknown)))
                command, new = s.dispense(body.get("command_id"), body.get("compartment"), body.get("unit_number"))
                return (202 if new else 200), {"command": command, "replayed": not new}
            if method == "GET" and len(route) == 2 and route[0] == "dispense":
                return 200, {"command": s.command(route[1])}
            if method == "POST" and route == ["stop"]:
                reason = body.get("reason", "stop requested")
                return 200, s.stop(reason if isinstance(reason, str) else "stop requested")
            if method == "POST" and route == ["lockout"]:
                engaged = body.get("engaged")
                if not isinstance(engaged, bool):
                    raise ServiceError(422, "invalid_engaged", "engaged must be true or false")
                return 200, s.set_lockout(engaged, _note(body))
            if method == "GET" and route == ["anomalies"]:
                return 200, {"anomalies": s.journal.anomalies(include_cleared=query.get("all", ["0"])[0] == "1")}
            if method == "POST" and len(route) == 3 and route[0] == "anomalies" and route[2] == "clear":
                if not route[1].isdigit():
                    raise ServiceError(422, "invalid_anomaly", "anomaly id must be an integer")
                s.clear_anomaly(int(route[1]), _note(body))
                return 200, {"cleared": int(route[1])}
            if method == "POST" and route == ["maintenance", "servo"]:
                return 200, s.servo_test(body.get("compartment"), body.get("angle"), body.get("hold_ms", 800))
            if route[:1] == ["sim"]:
                sim = s.sim()
                if method == "POST" and route == ["sim", "coin"]:
                    try:
                        pulses = sim.inject_coin(body.get("centavos"), body.get("pulses"), body.get("pulse_ms"))
                    except (TypeError, ValueError) as error:
                        raise ServiceError(422, "invalid_sim_coin", str(error)) from error
                    return 202, {"simulated": True, "pulses": pulses}
                if method == "POST" and route == ["sim", "beam"]:
                    try:
                        sim.queue_beam(body.get("scenario"))  # type: ignore[arg-type]
                    except ValueError as error:
                        raise ServiceError(422, "invalid_scenario", str(error)) from error
                    return 200, {"simulated": True, "queued": body.get("scenario")}
                if method == "POST" and route == ["sim", "estop"]:
                    if not isinstance(body.get("engaged"), bool):
                        raise ServiceError(422, "invalid_engaged", "engaged must be true or false")
                    sim.estop = body["engaged"]
                    return 200, {"simulated": True, "estop": sim.estop}
            return None

        def do_GET(self) -> None:  # noqa: N802
            self._dispatch("GET")

        def do_POST(self) -> None:  # noqa: N802
            self._dispatch("POST")

    return Handler


def make_server(service: HardwareService, token: str, host: str, port: int) -> ThreadingHTTPServer:
    server = ThreadingHTTPServer((host, port), make_handler(service, token))
    server.daemon_threads = True
    return server
