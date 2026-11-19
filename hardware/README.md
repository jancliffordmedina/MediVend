# MediVend hardware service (`medivend-hwd`)

A small Python service on the Orange Pi One that owns the physical I/O:

| Device | Interface | Code |
| --- | --- | --- |
| Coin acceptor (pulse output) | GPIO edge events via libgpiod | `signals.CoinDecoder`, `backends.RealBackend` |
| IR break-beam (shared chute) | GPIO edge events via libgpiod | `signals.BeamWindow` |
| PCA9685 + 3 × SG90 | I²C via `smbus2` (or Debian `python3-smbus`) | `pca9685.PCA9685` |
| PCA9685 /OE, optional E-stop contact | GPIO output / input | `backends.RealBackend` |

It **never writes to MariaDB**. PHP stays the only owner of money, orders and stock. The PHP hardware worker (`scripts/hardware-worker.php`) reaches this service through `includes/hardware-http-adapter.php`:

```
Chromium kiosk ─► Apache/PHP ─► MariaDB
                        ▲
     scripts/hardware-worker.php ── MedivendHttpHardwareAdapter ──HTTP 127.0.0.1:8765 + token──► medivend-hwd
                                                                                                ├─ backend=real : gpiod + PCA9685
                                                                                                └─ backend=sim  : simulated coins/beam
```

Dependencies: Python 3.11+ standard library. The real backend also needs `gpiod` (libgpiod v1 or v2 bindings) and `smbus2`/`smbus`. No Flask/FastAPI.

## Guarantees

* **Coins are journaled before PHP can see them** (SQLite, WAL, `synchronous=FULL`). Each coin gets a random 64-hex event ID and stays in the journal until PHP has committed it and acknowledged it. Pulse trains with an unknown count, out-of-range widths or a stuck line are recorded as **anomalies**. These are never guessed into a value, and they make the service *not ready* until staff clear them.
* **A command ID is persisted before any motor output.** Repeating it returns the stored status (HTTP 200) and never moves the motor again. If the service restarts during a cycle, that command is reported as `uncertain`. It is never retried.
* **Only a beam passage counts as delivery.** Each dispense cycle runs: pre-check that the beam is clear → enable /OE → servo to the dispense angle → hold → back to rest → servo off → /OE off → watch the beam. The outcome is decided like this:
  * Exactly one passage → `sensor_confirmed`.
  * No passage → `timeout`.
  * Two or more passages, or a stop or E-stop mid-cycle → `uncertain`.
  * Beam blocked longer than `max_block_ms` (or already blocked before the motor moves) → `jam`.
  * An item that drops after the window closes is **not** a confirmation.
* **Only one command runs at a time.** The PHP worker also serialises units.
* **`stop` is not latched.** It aborts motion and switches outputs off. A latched **maintenance lockout** and the **E-stop input** both make the service not ready. The E-stop must still cut servo V+ in hardware. Software is not a safety stop.
* **Simulation is always labelled.** Health reports `"simulated": true` for the sim backend. PHP `hardware` mode refuses a simulated service, and `device_simulation` mode refuses a real one.

## Run the simulator locally (Windows/XAMPP or the Pi)

```powershell
cd C:\xampp\htdocs\MediVend\hardware
py -m medivend_hwd init-token .local/hwd.token
py -m medivend_hwd --config config/hwd.sim.toml serve
```

In a second terminal (same folder):

```powershell
py -m medivend_hwd --config config/hwd.sim.toml health
py -m medivend_hwd --config config/hwd.sim.toml sim-coin 10          # ₱10 (pesos: 1, 5, 10 or 20)
py -m medivend_hwd --config config/hwd.sim.toml sim-coin --pulses 3  # invalid train -> anomaly
py -m medivend_hwd --config config/hwd.sim.toml anomalies
py -m medivend_hwd --config config/hwd.sim.toml clear-anomaly 1 --note "simulated test"
py -m medivend_hwd --config config/hwd.sim.toml sim-beam jam         # next cycle: pass|none|jam|double|late
py -m medivend_hwd --config config/hwd.sim.toml events
```

To drive the full PHP flow against it, run PHP in `MEDIVEND_MODE=device_simulation`. Configure a private adapter file outside the web project and set `MEDIVEND_DELIVERY_TIMEOUT_SECONDS`, then run `php scripts/hardware-worker.php`. The kiosk shows a **Device simulator** badge, and only demo inventory is used. `tests/device-simulation.test.php` automates the whole loop.

## Coin-test mode: real coin slot, simulated servos and beam (Orange Pi)

To test the **physical coin acceptor** through the whole kiosk payment flow before the servos and IR beam are wired:

1. Wire the acceptor through the optocoupler, then confirm the pulses with `bench-coin` (checklist below).
2. Copy `config/hwd.coin-test.toml` to `/etc/medivend-hw/hwd.toml`, set `[coin.input] line` and the verified pulse settings, then `sudo systemctl restart medivend-hwd`.
3. In `/etc/medivend/medivend.env`, set `MEDIVEND_MODE=device_simulation`, the adapter file, and `MEDIVEND_DELIVERY_TIMEOUT_SECONDS` (above `max_cycle_ms` + 2 s). Restart the worker and Apache.
4. At the kiosk, which shows the **Device simulator** badge: save an order, then insert **real coins**. Paid orders "dispense" through the simulated beam (`sim-beam` still chooses the outcome). Only demo inventory is used.

`sim-coin` is refused in this mode, so every credit comes from a real coin. Unknown pulse trains still become anomalies and block the machine. Health reports `"simulated": true`, so `MEDIVEND_MODE=hardware` refuses this backend.

## Tests

```powershell
cd hardware; py -m unittest discover -s tests -v                      # 30 unit/API tests (simulator, fake I2C bus)
cd ..; & 'C:\xampp\php\php.exe' tests/device-simulation.test.php      # PHP worker + adapter + Python sim, end to end
```

These are **simulation and logic tests only**. They do not show that any physical device works.

## API (localhost only; `Authorization: Bearer <token>`)

Responses use `{"ok": true, "data": …}` or `{"ok": false, "error": {"code", "message"}}`. POST bodies must be JSON objects of at most 4 KiB.

| Method & path | Purpose | Notes |
| --- | --- | --- |
| `GET /v1/health` | Readiness and capability flags | `ready` requires connected, coin/sensor verified, dedup, persistent IDs, safe stop, and denominations exactly `[500,1000,2000]` (₱5, ₱10, ₱20). `details.max_cycle_ms` is the longest possible cycle. |
| `GET /v1/events?limit=50` | Unacknowledged observations, oldest first | `accepted_coin {event_id, coin_centavos, observed_at}`, `delivery {event_id, command_id, outcome, observed_at}` |
| `POST /v1/events/{id}/ack` | Acknowledge after the PHP commit | Idempotent; 404 for an unknown ID |
| `POST /v1/dispense` | `{command_id, compartment, unit_number}` | 202 new; 200 replay (never re-actuated); 409 busy/conflict; 423 not ready |
| `GET /v1/dispense/{command_id}` | Command status | `received`, `actuating`, `watching`, then a final outcome |
| `POST /v1/stop` | Abort motion, outputs off | 503 if the outputs can't be confirmed off |
| `POST /v1/lockout` | `{engaged, note}` latched maintenance lockout | Required for servo tests |
| `GET /v1/anomalies`, `POST /v1/anomalies/{id}/clear` | Coin anomalies (`{note}`) | |
| `POST /v1/maintenance/servo` | `{compartment, angle, hold_ms}` calibration move | Lockout required; never during a command |
| `POST /v1/sim/coin`, `/v1/sim/beam`, `/v1/sim/estop` | Simulator controls | 404 on the real backend |

## Deploying on the Orange Pi One (not yet executed on the target)

```sh
sudo apt install python3 python3-libgpiod python3-smbus2 i2c-tools gpiod   # or python3-smbus; VERIFY package names on the image
sudo groupadd --system gpio; sudo groupadd --system i2c; sudo groupadd --system medivend-hw
sudo adduser --system --group --no-create-home medivend-hwd
sudo usermod -a -G medivend-hw medivend          # the PHP worker reads the token
sudo install -d -m 0750 -o root -g medivend-hw /etc/medivend-hw   # kept apart from medivend.env (DB password)
sudo install -m 0644 /srv/medivend/deploy/99-medivend-hardware.rules /etc/udev/rules.d/
sudo udevadm control --reload && sudo udevadm trigger
# Enable I2C0 (Armbian): add "i2c0" to overlays= in /boot/armbianEnv.txt, reboot, then:
i2cdetect -l; sudo i2cdetect -y 0        # expect the PCA9685 at 0x40 (and the 0x70 all-call address)
gpioinfo                                 # confirm line offsets before writing them in the config
sudo install -m 0640 -o root -g medivend-hw /srv/medivend/hardware/config/hwd.example.toml /etc/medivend-hw/hwd.toml
sudo sh -c 'cd /srv/medivend/hardware && PYTHONPATH=. python3 -m medivend_hwd init-token /etc/medivend-hw/hwd.token'
sudo chgrp medivend-hw /etc/medivend-hw/hwd.token
sudo install -m 0644 /srv/medivend/deploy/medivend-hwd.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now medivend-hwd
journalctl -u medivend-hwd -f
```

Practise on the Pi with `backend = "sim"` and `MEDIVEND_MODE=device_simulation` first. Switch to `backend = "real"` and `MEDIVEND_MODE=hardware` only after the checklist below passes.

## Bench commissioning checklist (hardware team)

Stop the service (`systemctl stop medivend-hwd`) before running the bench tools, because they open the GPIO and I²C devices directly.

**Electrical (before connecting anything to the Pi)**
1. Measure every signal that goes to a GPIO. The Orange Pi One's GPIO is **3.3 V only**. A 12 V coin acceptor output must go through an optocoupler (e.g. PC817) or a verified open-collector stage with its pull-up to 3.3 V. Never connect 5 V or 12 V directly.
2. Wire the PCA9685 **VCC to 3.3 V** (this keeps the I²C pull-ups at 3.3 V) and **V+ to the servo buck converter**. Size that converter for all three SG90 stalls at once (≈0.65 A+ each, so ≥3 A). Never power servos from the Pi.
3. Use a common ground: Pi, PCA9685, buck converters, coin acceptor logic (if not isolated) and the IR receiver.
4. Wire **/OE** to a GPIO with a pull-up so the outputs stay *disabled* during boot. Fit an E-stop that **cuts servo V+**, plus an optional NC status contact to a GPIO.

**Signals**
1. `bench-pca`: the prescale reads back and the measured period is 20 ms ±2 %. If not, adjust `oscillator_hz`.
2. `bench-coin`: insert each coin type 20 times. Record pulse count and width, set `pulses` / `pulse_min_ms` / `pulse_max_ms` / `train_gap_ms`, and check that ACTIVE and inactive match the real signal (`active_low`). Insert two coins quickly and confirm the trains don't merge.
3. `bench-beam`: block the beam by hand and with each product. Confirm ACTIVE means *blocked* and note the shortest real block duration (this sets `debounce_ms` below it).
4. With the lockout on, run `servo-test <compartment> <angle>` in small steps to find `rest_angle` and `dispense_angle`. Then time a full cycle to set `move_ms` and `hold_ms`.
5. Run 50 cycles per compartment through `device_simulation`, then `hardware`, with test inventory. Count physical drops against `sensor_confirmed`. Any double drop or miss means adjusting the mechanism or beam position before you set `commissioned = true`.

Record measured values and results in the commissioning log. **Don't set `commissioned = true` from a simulated result.**
