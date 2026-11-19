"""Command line: run the service, call its API, and bench tools.

  python -m medivend_hwd --config /etc/medivend-hw/hwd.toml serve
  python -m medivend_hwd init-token /etc/medivend-hw/hwd.token
  python -m medivend_hwd --config ... health | events | stop | anomalies
  python -m medivend_hwd --config ... lockout on|off --note "..."
  python -m medivend_hwd --config ... clear-anomaly ID --note "..."
  python -m medivend_hwd --config ... servo-test COMPARTMENT ANGLE [--hold-ms 800]
  python -m medivend_hwd --config ... sim-coin 10      (pesos: 1, 5, 10 or 20) | sim-coin --pulses 3
  python -m medivend_hwd --config ... sim-beam pass|none|jam|double|late
  python -m medivend_hwd --config ... sim-estop on|off
  Bench tools (stop the service first; they open the GPIO/I2C devices directly):
  python -m medivend_hwd --config ... bench-coin [--seconds 60]
  python -m medivend_hwd --config ... bench-beam [--seconds 60]
  python -m medivend_hwd --config ... bench-pca
"""
from __future__ import annotations

import argparse
import json
import logging
import os
import secrets
import signal
import sys
import threading
import time
import urllib.error
import urllib.request
from typing import Any, Optional

from .config import Config, load, read_token

DEFAULT_CONFIG = os.environ.get("MEDIVEND_HWD_CONFIG", "/etc/medivend-hw/hwd.toml")


def serve(config: Config) -> int:
    from .api import make_server
    from .backends import create_backend
    from .journal import Journal
    from .service import HardwareService

    token = read_token(config.token_file)
    journal = Journal(config.journal_path)
    service = HardwareService(config, create_backend(config), journal)
    service.start()
    server = make_server(service, token, config.host, config.port)
    stopping = threading.Event()

    def shutdown(*_: Any) -> None:
        if not stopping.is_set():
            stopping.set()
            threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)
    if config.simulated:
        logging.getLogger("medivend_hwd").warning("SIMULATION BACKEND: no real coins, motors or sensors are used")
    logging.getLogger("medivend_hwd").info("listening on http://%s:%s", config.host, config.port)
    try:
        server.serve_forever(poll_interval=0.2)
    finally:
        service.close()
        journal.close()
    return 0


def call(config: Config, method: str, path: str, body: Optional[dict[str, Any]] = None) -> tuple[int, Any]:
    data = None if body is None else json.dumps(body).encode()
    request = urllib.request.Request(f"http://{config.host}:{config.port}/v1/{path}", data=data, method=method)
    request.add_header("Authorization", "Bearer " + read_token(config.token_file))
    if data is not None:
        request.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            return response.status, json.loads(response.read())
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")
    except (urllib.error.URLError, ConnectionError, TimeoutError) as error:
        return 0, {"ok": False, "error": {"code": "service_unreachable",
                   "message": f"Hardware service is not running at {config.host}:{config.port} ({getattr(error, 'reason', error)}). Start it with: serve"}}


def bench_input(config: Config, which: str, seconds: float) -> int:
    from .backends import _GpioLine
    from .signals import CoinDecoder

    spec = config.coin.input if which == "coin" else config.beam.input
    if spec is None:
        print(f"{which}.input is not configured", file=sys.stderr)
        return 2
    line = _GpioLine(spec, edges=True)
    c = config.coin
    decoder = CoinDecoder(c.pulses, c.glitch_ms, c.pulse_min_ms, c.pulse_max_ms, c.train_gap_ms)
    print(f"Watching {which} on {spec.chip} line {spec.line} (active_low={spec.active_low}) for {seconds:.0f} s. Current active={line.active()}")
    end, last = time.monotonic() + seconds, None
    try:
        while time.monotonic() < end:
            for active, ts in line.wait_edges(0.05):
                delta = "" if last is None else f" (+{(ts - last) / 1e6:.1f} ms)"
                last = ts
                print(f"{ts / 1e9:.6f} {'ACTIVE' if active else 'inactive'}{delta}")
                if which == "coin":
                    decoder.edge(active, ts, time.monotonic_ns())
            if which == "coin":
                result = decoder.poll(time.monotonic_ns())
                if result:
                    print(f"  -> train of {result.pulses} pulses: " + (f"ANOMALY {result.anomaly}" if result.anomaly else f"{result.centavos} centavos"))
    finally:
        line.close()
    return 0


def bench_pca(config: Config) -> int:
    from .pca9685 import PCA9685

    s = config.servo
    pwm = PCA9685.open(s.i2c_bus, s.address, s.frequency_hz, s.oscillator_hz)
    try:
        pwm.initialise()
        print(f"PCA9685 at 0x{s.address:02x} on i2c-{s.i2c_bus}: prescale={pwm.prescale} actual_frequency={pwm.frequency:.2f} Hz probe={pwm.probe()}")
        print("All outputs left OFF. Use 'servo-test' through the running service for movement.")
    finally:
        pwm.all_off()
        pwm.close()
    return 0


def main(argv: Optional[list[str]] = None) -> int:
    parser = argparse.ArgumentParser(prog="medivend_hwd", description="MediVend hardware service")
    parser.add_argument("--config", default=DEFAULT_CONFIG)
    parser.add_argument("--log-level", default=os.environ.get("MEDIVEND_HWD_LOG_LEVEL", "INFO"))
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("serve")
    token = sub.add_parser("init-token")
    token.add_argument("path")
    for name in ("health", "events", "stop", "anomalies", "bench-pca"):
        sub.add_parser(name)
    lockout = sub.add_parser("lockout")
    lockout.add_argument("state", choices=["on", "off"])
    lockout.add_argument("--note", required=True)
    clear = sub.add_parser("clear-anomaly")
    clear.add_argument("id", type=int)
    clear.add_argument("--note", required=True)
    servo = sub.add_parser("servo-test")
    servo.add_argument("compartment", type=int)
    servo.add_argument("angle", type=float)
    servo.add_argument("--hold-ms", type=int, default=800)
    coin = sub.add_parser("sim-coin")
    coin.add_argument("pesos", type=int, nargs="?", help="coin value in pesos: 5, 10 or 20 (any other value is rejected and shown on the kiosk)")
    coin.add_argument("--pulses", type=int)
    coin.add_argument("--pulse-ms", type=float)
    beam = sub.add_parser("sim-beam")
    beam.add_argument("scenario")
    estop = sub.add_parser("sim-estop")
    estop.add_argument("state", choices=["on", "off"])
    for name in ("bench-coin", "bench-beam"):
        sub.add_parser(name).add_argument("--seconds", type=float, default=60)
    args = parser.parse_args(argv)
    logging.basicConfig(level=args.log_level.upper(), format="%(asctime)s %(levelname)s %(name)s %(message)s")

    if args.command == "init-token":
        if os.path.exists(args.path):
            print("token file already exists; not overwritten", file=sys.stderr)
            return 1
        parent = os.path.dirname(os.path.abspath(args.path))
        os.makedirs(parent, mode=0o750, exist_ok=True)
        fd = os.open(args.path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
        with os.fdopen(fd, "w", encoding="ascii") as handle:
            handle.write(secrets.token_hex(32) + "\n")
        print(f"created {args.path} (mode 0640). Set its group so only the worker and service can read it.")
        return 0

    config = load(args.config)
    if args.command == "serve":
        return serve(config)
    if args.command in ("bench-coin", "bench-beam"):
        return bench_input(config, args.command.split("-")[1], args.seconds)
    if args.command == "bench-pca":
        return bench_pca(config)
    requests = {
        "health": ("GET", "health", None),
        "events": ("GET", "events", None),
        "stop": ("POST", "stop", {"reason": "operator CLI"}),
        "anomalies": ("GET", "anomalies", None),
    }
    if args.command in requests:
        method, path, body = requests[args.command]
    elif args.command == "lockout":
        method, path, body = "POST", "lockout", {"engaged": args.state == "on", "note": args.note}
    elif args.command == "clear-anomaly":
        method, path, body = "POST", f"anomalies/{args.id}/clear", {"note": args.note}
    elif args.command == "servo-test":
        angle: Any = int(args.angle) if args.angle.is_integer() else args.angle
        method, path, body = "POST", "maintenance/servo", {"compartment": args.compartment, "angle": angle, "hold_ms": args.hold_ms}
    elif args.command == "sim-coin":
        accepted = {value // 100: value for value in config.coin.pulses.values()}
        listed = ", ".join(f"PHP {p}" for p in sorted(accepted))
        if args.pulses:
            body = {"pulses": args.pulses}
        elif args.pesos is None or not 1 <= args.pesos <= 1000:
            print(json.dumps({"ok": False, "error": {"code": "invalid_coin",
                  "message": f"Give a coin value in pesos (1-1000). Accepted coins: {listed}."}}, indent=2))
            return 1
        elif args.pesos not in accepted:
            # Unsupported coin: the acceptor returns it. Nothing is credited, and
            # the kiosk shows "Coin not accepted" on the Payment screen.
            status, payload = call(config, "POST", "sim/reject", {"centavos": args.pesos * 100})
            if 200 <= status < 300:
                payload["data"]["message"] = (f"PHP {args.pesos} is not an accepted coin ({listed}). "
                                              "It was returned; the kiosk shows Coin not accepted. Nothing was credited.")
            print(json.dumps(payload, indent=2))
            return 0 if 200 <= status < 300 else 1
        else:
            body = {"centavos": accepted[args.pesos]}
        if args.pulse_ms:
            body["pulse_ms"] = args.pulse_ms
        method, path = "POST", "sim/coin"
    elif args.command == "sim-beam":
        method, path, body = "POST", "sim/beam", {"scenario": args.scenario}
    else:
        method, path, body = "POST", "sim/estop", {"engaged": args.state == "on"}
    status, payload = call(config, method, path, body)
    print(json.dumps(payload, indent=2))
    return 0 if 200 <= status < 300 else 1


if __name__ == "__main__":
    sys.exit(main())
