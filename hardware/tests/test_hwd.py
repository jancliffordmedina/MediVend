"""Unit and integration tests for the hardware service (simulator only).

Run from hardware/:  py -m unittest discover -s tests -v
These tests exercise logic and the HTTP API. They are NOT physical hardware tests.
"""
from __future__ import annotations

import copy
import json
import os
import secrets
import sys
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from medivend_hwd import config as cfg  # noqa: E402
from medivend_hwd.api import make_server  # noqa: E402
from medivend_hwd.backends import SimBackend  # noqa: E402
from medivend_hwd.journal import Journal  # noqa: E402
from medivend_hwd.pca9685 import PCA9685, angle_to_pulse_us, prescale_for, ticks_for  # noqa: E402
from medivend_hwd.service import HardwareService, ServiceError  # noqa: E402
from medivend_hwd.signals import MS, BeamWindow, CoinDecoder  # noqa: E402

BASE = {
    "service": {"backend": "sim", "token_file": "unused", "journal_path": ":memory:"},
    "coin": {"pulses": {"5": 500, "10": 1000, "20": 2000}, "glitch_ms": 2, "pulse_min_ms": 5,
             "pulse_max_ms": 30, "train_gap_ms": 60},
    "beam": {"debounce_ms": 2, "max_block_ms": 250, "watch_after_return_ms": 250, "settle_ms": 60},
    "servo": {"min_pulse_us": 1000, "max_pulse_us": 2000},
    "sim": {"pulse_ms": 10, "pulse_gap_ms": 10, "beam_delay_ms": 30, "beam_block_ms": 15},
    "compartments": [{"number": n, "channel": n - 1, "rest_angle": 0, "dispense_angle": 90, "move_ms": 40, "hold_ms": 10}
                     for n in (1, 2, 3)],
}


def make_config(**overrides) -> cfg.Config:
    data = copy.deepcopy(BASE)
    for section, values in overrides.items():
        data[section].update(values) if isinstance(values, dict) else data.__setitem__(section, values)
    return cfg.parse(data)


def wait_for(predicate, timeout=5.0):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        value = predicate()
        if value:
            return value
        time.sleep(0.01)
    raise AssertionError("condition not reached")


class ConfigTests(unittest.TestCase):
    def test_denominations_must_be_exact(self):
        with self.assertRaises(cfg.ConfigError):
            make_config(coin={"pulses": {"1": 100, "5": 500, "10": 1000, "20": 2000}})  # ₱1 is no longer accepted

    def test_real_backend_requires_explicit_lines(self):
        with self.assertRaisesRegex(cfg.ConfigError, "coin.input.line is required"):
            make_config(service={"backend": "real"})

    def test_non_loopback_host_rejected(self):
        with self.assertRaises(cfg.ConfigError):
            make_config(service={"host": "0.0.0.0"})

    def test_duplicate_channel_rejected(self):
        comps = copy.deepcopy(BASE["compartments"])
        comps[1]["channel"] = 0
        with self.assertRaises(cfg.ConfigError):
            make_config(compartments=comps)

    def test_example_files_parse(self):
        root = os.path.join(os.path.dirname(__file__), "..", "config")
        self.assertTrue(cfg.load(os.path.join(root, "hwd.sim.toml")).simulated)
        with self.assertRaisesRegex(cfg.ConfigError, "line is required"):
            cfg.load(os.path.join(root, "hwd.example.toml"))  # pins deliberately unset


class CoinDecoderTests(unittest.TestCase):
    def decoder(self):
        return CoinDecoder({5: 500, 10: 1000, 20: 2000}, 2, 5, 30, 60)

    def feed(self, decoder, widths_ms, gap_ms=10, start=0):
        t = start
        for width in widths_ms:
            decoder.edge(True, t, t)
            t += int(width * MS)
            decoder.edge(False, t, t)
            t += int(gap_ms * MS)
        return t

    def test_counts_and_maps(self):
        for count, value in ((5, 500), (10, 1000), (20, 2000)):
            d = self.decoder()
            end = self.feed(d, [10] * count)
            self.assertIsNone(d.poll(end))  # train not finished yet
            result = d.poll(end + 80 * MS)
            self.assertEqual((result.centavos, result.pulses, result.anomaly), (value, count, None))

    def test_glitches_ignored(self):
        d = self.decoder()
        end = self.feed(d, [10, 0.5, 10, 10, 10, 10])
        self.assertEqual(d.poll(end + 80 * MS).centavos, 500)
        self.assertEqual(d.ignored_glitches, 1)

    def test_one_pulse_peso_coin_is_now_an_anomaly(self):
        d = self.decoder()
        end = self.feed(d, [10])
        self.assertIn("unknown pulse count 1", d.poll(end + 80 * MS).anomaly)

    def test_unknown_count_is_anomaly(self):
        d = self.decoder()
        end = self.feed(d, [10] * 3)
        result = d.poll(end + 80 * MS)
        self.assertIsNone(result.centavos)
        self.assertIn("unknown pulse count 3", result.anomaly)

    def test_bad_width_is_anomaly(self):
        d = self.decoder()
        end = self.feed(d, [10, 50])
        self.assertIn("pulse width", d.poll(end + 80 * MS).anomaly)

    def test_stuck_line(self):
        d = self.decoder()
        d.edge(True, 0, 0)
        self.assertIn("held active", d.poll(200 * MS).anomaly)


class BeamWindowTests(unittest.TestCase):
    def window(self):
        return BeamWindow(int(2 * MS), int(100 * MS), False)

    def test_outcomes(self):
        w = self.window()
        self.assertEqual(w.outcome()[0], "timeout")
        w.edge(True, 0, 0); w.edge(False, 0, 20 * MS)
        self.assertEqual(w.outcome()[0], "sensor_confirmed")
        w.edge(True, 0, 40 * MS); w.edge(False, 0, 60 * MS)
        self.assertEqual(w.outcome()[0], "uncertain")

    def test_debounce_and_jam(self):
        w = self.window()
        w.edge(True, 0, 0); w.edge(False, 0, MS)  # 1 ms glitch
        self.assertEqual((w.passages, w.glitches), (0, 1))
        w.edge(True, 0, 10 * MS)
        self.assertTrue(w.check_jam(200 * MS))
        self.assertEqual(w.outcome()[0], "jam")


class FakeBus:
    def __init__(self):
        self.registers = {0x00: 0x11}
        self.blocks = {}

    def read_byte_data(self, address, register):
        return self.registers.get(register, 0)

    def write_byte_data(self, address, register, value):
        self.registers[register] = value

    def write_i2c_block_data(self, address, register, data):
        self.blocks[register] = list(data)


class PCA9685Tests(unittest.TestCase):
    def test_math(self):
        self.assertEqual(prescale_for(25_000_000, 50), 121)
        self.assertEqual(ticks_for(1500, 50), 307)
        self.assertEqual(angle_to_pulse_us(90, 1000, 2000), 1500)
        with self.assertRaises(ValueError):
            angle_to_pulse_us(181, 1000, 2000)

    def test_register_writes(self):
        bus = FakeBus()
        pwm = PCA9685(bus, 0x40, 50, 25_000_000)
        pwm.initialise()
        self.assertTrue(pwm.probe())
        self.assertEqual(bus.registers[0xFE], 121)
        self.assertTrue(bus.registers[0x00] & 0x20 and not bus.registers[0x00] & 0x10)  # auto-increment, awake
        pwm.set_pulse_us(2, 1500)
        ticks = round(1500 * pwm.frequency * 4096 / 1e6)
        self.assertEqual(bus.blocks[0x06 + 8], [0, 0, ticks & 0xFF, ticks >> 8])
        pwm.set_pulse_us(2, None)
        self.assertEqual(bus.blocks[0x0E], [0, 0, 0, 0x10])


class ServiceTests(unittest.TestCase):
    def setUp(self):
        self.config = make_config()
        self.backend = SimBackend(self.config)
        self.journal = Journal(":memory:")
        self.service = HardwareService(self.config, self.backend, self.journal)
        self.service.start()

    def tearDown(self):
        self.service.close()

    def dispense(self, scenario=None, compartment=1):
        if scenario:
            self.backend.queue_beam(scenario)
        command_id = secrets.token_hex(32)
        self.service.dispense(command_id, compartment, 1)
        return command_id, wait_for(lambda: next((e for e in self.journal.unacked_events() if e.get("command_id") == command_id), None))

    def test_health_is_ready_and_marked_simulated(self):
        health = self.service.health()
        self.assertTrue(health["ready"])
        self.assertTrue(health["simulated"])
        self.assertEqual(health["denominations_centavos"], [500, 1000, 2000])

    def test_simulated_coins_flow_through_decoder_and_journal(self):
        self.backend.inject_coin(2000)
        event = wait_for(lambda: self.journal.unacked_events())[0]
        self.assertEqual((event["type"], event["coin_centavos"]), ("accepted_coin", 2000))
        self.assertTrue(abs(event["observed_at"] - time.time()) < 5)
        self.service.acknowledge(event["event_id"])
        self.service.acknowledge(event["event_id"])  # idempotent
        self.assertEqual(self.journal.unacked_events(), [])

    def test_back_to_back_coins_are_separate_events(self):
        self.backend.inject_coin(1000)
        self.backend.inject_coin(500)
        events = wait_for(lambda: (e := self.journal.unacked_events()) and len(e) == 2 and e)
        self.assertEqual([e["coin_centavos"] for e in events], [1000, 500])
        self.assertEqual(self.journal.anomalies(), [])

    def test_unknown_pulses_block_coin_readiness(self):
        self.backend.inject_coin(pulses=3)
        wait_for(lambda: self.journal.anomalies())
        self.assertEqual(self.journal.unacked_events(), [])
        self.assertFalse(self.service.health()["coin_events_verified"])
        with self.assertRaises(ServiceError) as error:
            self.service.dispense(secrets.token_hex(32), 1, 1)
        self.assertEqual(error.exception.code, "not_ready")
        self.service.clear_anomaly(self.journal.anomalies()[0]["id"], "bench test cleared")
        self.assertTrue(self.service.health()["ready"])

    def test_outcomes(self):
        for scenario, expected in (("pass", "sensor_confirmed"), ("none", "timeout"), ("double", "uncertain"), ("jam", "jam")):
            with self.subTest(scenario=scenario):
                _, event = self.dispense(scenario)
                self.assertEqual(event["outcome"], expected)
                wait_for(lambda: self.service.health()["details"]["active_command"] is None)
                wait_for(lambda: not self.backend.beam_blocked())
                time.sleep(0.3)  # let a jam's stuck-beam idle timer clear

    def test_servo_sequence_and_outputs_off(self):
        self.dispense("pass", compartment=2)
        moves = [w for w in self.backend.servo_writes if w[0] == 1]
        self.assertEqual(moves, [(1, 1500.0), (1, 1000.0), (1, None), (1, None)])
        self.assertFalse(self.backend.outputs_enabled)

    def test_duplicate_command_is_never_actuated_twice(self):
        command_id, event = self.dispense("pass")
        writes = len(self.backend.servo_writes)
        command, new = self.service.dispense(command_id, 1, 1)
        self.assertFalse(new)
        self.assertEqual(command["status"], "sensor_confirmed")
        self.assertEqual(len(self.backend.servo_writes), writes)
        with self.assertRaises(ServiceError) as error:
            self.service.dispense(command_id, 2, 1)
        self.assertEqual(error.exception.code, "command_conflict")

    def test_busy_and_stop_mid_cycle(self):
        self.backend.queue_beam("none")
        first = secrets.token_hex(32)
        self.service.dispense(first, 1, 1)
        with self.assertRaises(ServiceError) as error:
            self.service.dispense(secrets.token_hex(32), 2, 1)
        self.assertEqual(error.exception.code, "busy")
        self.service.stop("test")
        event = wait_for(lambda: next((e for e in self.journal.unacked_events() if e.get("command_id") == first), None))
        self.assertEqual(event["outcome"], "uncertain")
        self.assertFalse(self.backend.outputs_enabled)

    def test_late_item_is_not_confirmation(self):
        _, event = self.dispense("late")
        self.assertEqual(event["outcome"], "timeout")

    def test_restart_marks_in_flight_uncertain(self):
        command_id = secrets.token_hex(32)
        self.journal.claim_command(command_id, 1, 1)
        self.journal.set_command_status(command_id, "actuating")
        restarted = HardwareService(self.config, SimBackend(self.config), self.journal)
        restarted.start()
        try:
            self.assertEqual(self.journal.command(command_id)["status"], "uncertain")
            restart_event = next(e for e in self.journal.unacked_events() if e.get("command_id") == command_id)
            self.assertEqual(restart_event["outcome"], "uncertain")
            command, new = restarted.dispense(command_id, 1, 1)
            self.assertFalse(new)  # never retried
        finally:
            restarted._shutdown.set()

    def test_lockout_and_estop_block_readiness(self):
        self.service.set_lockout(True, "maintenance test")
        self.assertFalse(self.service.health()["ready"])
        result = self.service.servo_test(1, 45, 100)
        self.assertEqual(result["pulse_us"], 1250)
        self.service.set_lockout(False, "maintenance done")
        with self.assertRaises(ServiceError) as error:
            self.service.servo_test(1, 45, 100)
        self.assertEqual(error.exception.code, "lockout_required")
        self.backend.estop = True
        self.assertFalse(self.service.health()["safe_stop"])
        self.backend.estop = False
        self.assertTrue(self.service.health()["ready"])

    def test_estop_during_cycle_is_uncertain(self):
        self.backend.queue_beam("none")
        command_id = secrets.token_hex(32)
        self.service.dispense(command_id, 1, 1)
        self.backend.estop = True
        event = wait_for(lambda: next((e for e in self.journal.unacked_events() if e.get("command_id") == command_id), None))
        self.assertEqual(event["outcome"], "uncertain")
        self.backend.estop = False


class FakeCoinLine:
    """Stands in for a GPIO line: replays scripted (active, ts_ns) edges once."""

    def __init__(self, spec, edges=False, output=None):
        self.queue, self.closed = [], False

    def wait_edges(self, timeout_s):
        if not self.queue:
            time.sleep(min(timeout_s, 0.01))
            return []
        batch, self.queue = self.queue, []
        return batch

    def close(self):
        self.closed = True


class CoinTestBackendTests(unittest.TestCase):
    def test_config_requires_coin_line_and_is_simulated(self):
        with self.assertRaisesRegex(cfg.ConfigError, "coin.input.line is required"):
            make_config(service={"backend": "coin_test"})
        config = make_config(service={"backend": "coin_test"}, coin={"input": {"line": 6, "active_low": True}})
        self.assertTrue(config.simulated)
        root = os.path.join(os.path.dirname(__file__), "..", "config")
        with self.assertRaisesRegex(cfg.ConfigError, "coin.input.line is required"):
            cfg.load(os.path.join(root, "hwd.coin-test.toml"))  # line deliberately unset

    def test_real_pulses_become_coins_while_dispensing_stays_simulated(self):
        from medivend_hwd.backends import CoinTestBackend
        config = make_config(service={"backend": "coin_test"}, coin={"input": {"line": 6, "active_low": True}})
        backend = CoinTestBackend(config, line_factory=FakeCoinLine)
        journal = Journal(":memory:")
        service = HardwareService(config, backend, journal)
        service.start()
        try:
            health = service.health()
            self.assertTrue(health["ready"] and health["simulated"] and health["backend"] == "coin_test")
            t = time.monotonic_ns()
            edges = []
            for _ in range(5):  # a ₱5 train: 5 pulses of 10 ms
                edges += [(True, t), (False, t + 10 * MS)]
                t += 20 * MS
            backend.coin_line.queue = edges
            event = wait_for(lambda: journal.unacked_events())[0]
            self.assertEqual(event["coin_centavos"], 500)
            with self.assertRaises(ValueError):
                backend.inject_coin(1000)  # no fake coins while the real slot is used
            command_id = secrets.token_hex(32)
            service.dispense(command_id, 1, 1)
            delivery = wait_for(lambda: next((e for e in journal.unacked_events() if e.get("command_id") == command_id), None))
            self.assertEqual(delivery["outcome"], "sensor_confirmed")
        finally:
            service.close()
        self.assertTrue(backend.coin_line.closed)


class JournalDurabilityTests(unittest.TestCase):
    def test_events_survive_reopen(self):
        with tempfile.TemporaryDirectory() as folder:
            path = os.path.join(folder, "journal.sqlite3")
            journal = Journal(path)
            coin = journal.record_coin(500)
            journal.close()
            reopened = Journal(path)
            self.assertEqual(reopened.unacked_events(), [coin])
            reopened.close()


class ApiTests(unittest.TestCase):
    def setUp(self):
        self.config = make_config()
        self.backend = SimBackend(self.config)
        self.service = HardwareService(self.config, self.backend, Journal(":memory:"))
        self.service.start()
        self.token = secrets.token_hex(32)
        self.server = make_server(self.service, self.token, "127.0.0.1", 0)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, kwargs={"poll_interval": 0.05}, daemon=True).start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.service.close()

    def call(self, method, path, body=None, token=None, raw=None, content_type="application/json"):
        data = raw if raw is not None else (None if body is None else json.dumps(body).encode())
        request = urllib.request.Request(f"http://127.0.0.1:{self.port}/v1/{path}", data=data, method=method)
        request.add_header("Authorization", "Bearer " + (token or self.token))
        if data is not None:
            request.add_header("Content-Type", content_type)
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                return response.status, json.loads(response.read())
        except urllib.error.HTTPError as error:
            with error:
                return error.code, json.loads(error.read())

    def test_auth_and_envelope(self):
        status, payload = self.call("GET", "health", token="wrong" * 10)
        self.assertEqual((status, payload["ok"], payload["error"]["code"]), (401, False, "unauthorised"))
        status, payload = self.call("GET", "health")
        self.assertEqual((status, payload["ok"]), (200, True))
        self.assertTrue(payload["data"]["simulated"])
        self.assertEqual(self.call("GET", "nope")[0], 404)

    def test_input_validation(self):
        self.assertEqual(self.call("POST", "dispense", raw=b"{bad")[1]["error"]["code"], "invalid_json")
        self.assertEqual(self.call("POST", "dispense", {"x": 1}, content_type="text/plain")[0], 415)
        self.assertEqual(self.call("POST", "dispense", {"command_id": "abc", "compartment": 1, "unit_number": 1})[0], 422)
        self.assertEqual(self.call("POST", "dispense", {"command_id": "a" * 64, "compartment": 9, "unit_number": 1})[0], 422)
        self.assertEqual(self.call("POST", "dispense", {"command_id": "a" * 64, "compartment": 1, "unit_number": 1, "extra": 1})[0], 422)
        self.assertEqual(self.call("POST", "sim/beam", {"scenario": "explode"})[0], 422)
        self.assertEqual(self.call("POST", "lockout", {"engaged": True, "note": "x"})[0], 422)

    def test_rejected_coin_credits_nothing_and_stays_ready(self):
        self.assertIsNone(self.call("GET", "health")[1]["data"]["details"]["last_rejected_coin_ms"])
        self.assertEqual(self.call("POST", "sim/reject", {"centavos": 1000})[0], 422)  # supported coins are not rejections
        self.assertEqual(self.call("POST", "sim/reject", {"centavos": True})[0], 422)
        status, payload = self.call("POST", "sim/reject", {"centavos": 100})
        self.assertEqual((status, payload["data"]["rejected_coins"]), (200, 1))
        health = self.call("GET", "health")[1]["data"]
        self.assertEqual(health["details"]["last_rejected_coin_ms"], payload["data"]["last_rejected_coin_ms"])
        self.assertTrue(health["ready"])
        self.assertEqual(self.call("GET", "events")[1]["data"]["events"], [])
        self.assertEqual(self.call("GET", "anomalies")[1]["data"]["anomalies"], [])

    def test_end_to_end_coin_and_dispense(self):
        self.assertEqual(self.call("POST", "sim/coin", {"centavos": 1000})[0], 202)
        events = wait_for(lambda: self.call("GET", "events")[1]["data"]["events"])
        self.assertEqual(events[0]["coin_centavos"], 1000)
        self.assertEqual(self.call("POST", f"events/{events[0]['event_id']}/ack", {})[0], 200)
        command_id = secrets.token_hex(32)
        status, payload = self.call("POST", "dispense", {"command_id": command_id, "compartment": 3, "unit_number": 1})
        self.assertEqual((status, payload["data"]["replayed"]), (202, False))
        status, payload = self.call("POST", "dispense", {"command_id": command_id, "compartment": 3, "unit_number": 1})
        self.assertEqual((status, payload["data"]["replayed"]), (200, True))
        wait_for(lambda: self.call("GET", f"dispense/{command_id}")[1]["data"]["command"]["status"] == "sensor_confirmed")
        delivery = self.call("GET", "events")[1]["data"]["events"][0]
        self.assertEqual((delivery["type"], delivery["command_id"], delivery["outcome"]), ("delivery", command_id, "sensor_confirmed"))
        self.assertEqual(self.call("POST", "stop", {})[0], 200)


if __name__ == "__main__":
    unittest.main()
