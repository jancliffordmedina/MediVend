-- Additive, re-runnable. When the coin acceptor returns an unsupported coin
-- (anything other than PHP 5 / 10 / 20) nothing is credited and the machine
-- stays available. The worker copies the device's last-rejection time here so
-- the kiosk can tell the customer the coin is not accepted.
ALTER TABLE hardware_machine_state ADD COLUMN IF NOT EXISTS last_rejected_coin_ms BIGINT UNSIGNED NULL;
