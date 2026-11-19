<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/hardware-flow.php';

function medivendWorkerTick(PDO $database, MedivendHardwareAdapter $adapter, array $config): bool
{
    $health = $adapter->health();
    $ready = medivendHardwareHealthVerified($health) && $config['delivery_timeout_seconds'] > 0 && $config['delivery_timeout_seconds'] <= 86400;
    $heartbeat = $database->prepare('UPDATE hardware_machine_state SET health = ?, config_hash = ?, heartbeat_at = CURRENT_TIMESTAMP WHERE id = 1');
    $heartbeat->execute([$ready ? 'ready' : 'unavailable', medivendHardwareConfigHash($config)]);
    // A returned (unsupported) coin credits nothing; only tell the kiosk about it.
    $rejectedAt = $health['details']['last_rejected_coin_ms'] ?? null;
    if (is_int($rejectedAt) && $rejectedAt > 0) {
        $database->prepare('UPDATE hardware_machine_state SET last_rejected_coin_ms = ? WHERE id = 1 AND (last_rejected_coin_ms IS NULL OR last_rejected_coin_ms < ?)')
            ->execute([$rejectedAt, $rejectedAt]);
    }
    if (!$ready) { medivendHardwareInterrupt($database); $adapter->stop(); return false; }
    foreach ($adapter->verifiedEvents() as $event) {
        if (($event['type'] ?? '') === 'accepted_coin') {
            // Bound to the active order under the machine lock; unbindable coins are
            // retained as unassigned and block the machine (never crash-loop here).
            medivendRecordHardwareCoin($database, $event);
        } elseif (($event['type'] ?? '') === 'delivery') {
            medivendHardwareDelivery($database, $event['event_id'], $event['command_id'], $event['outcome']);
        } else throw new RuntimeException('Unknown verified adapter event.');
        // Adapter acknowledgement only follows a committed result. The adapter
        // must retain every unacknowledged observation through process/power loss.
        $adapter->acknowledge($event['event_id']);
    }
    $expired = $database->query("SELECT id FROM hardware_commands WHERE status = 'sent' AND deadline_at < CURRENT_TIMESTAMP")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expired as $id) medivendHardwareDelivery($database, hash('sha256', 'deadline:' . $id), $id, 'timeout');
    // A late accepted coin for a just-closed session also blocks the machine.
    // Continue recording accepted value, but require staff before new motors.
    if (medivendHardwareBlockingIssues($database) > 0) {
        $database->exec("UPDATE hardware_machine_state SET health = 'unavailable' WHERE id = 1");
        medivendHardwareInterrupt($database); $adapter->stop(); return false;
    }
    $reference = $database->query('SELECT t.reference FROM hardware_machine_state m JOIN transactions t ON t.id = m.active_transaction_id WHERE m.id = 1')->fetchColumn();
    if (!$reference) return true;
    $order = medivendReadOrder($database, $reference);
    if ($order['order_status'] === 'needs_review') {
        medivendHardwareInterrupt($database); $adapter->stop(); return true;
    }
    $command = medivendQueueHardwareUnit($database, $reference);
    if ($command && medivendClaimHardwareCommand($database, $command['id'], $config['delivery_timeout_seconds'])) $adapter->dispatch($command);
    if (medivendReadOrder($database, $reference)['order_status'] === 'needs_review') {
        $database->exec("UPDATE hardware_machine_state SET health = 'unavailable' WHERE id = 1"); $adapter->stop(); return false;
    }
    return true;
}

// Requiring this file in tests exposes the tick function, without starting I/O.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$adapter = new MedivendUnconfiguredAdapter();
try {
    $config = medivendMachineConfig();
    if (!medivendHardwareFlow($config)) throw new RuntimeException('Worker requires server hardware or device_simulation mode.');
    $lock = fopen(getenv('MEDIVEND_WORKER_LOCK') ?: sys_get_temp_dir() . '/medivend-hardware-worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another hardware worker owns the lock.');
    $database = medivendDatabase();
    $config['adapter_digest'] = $config['adapter_file'] && is_readable($config['adapter_file']) ? hash_file('sha256', $config['adapter_file']) : '';
    $adapter = medivendLoadHardwareAdapter($config);
    // A previous sent command may have actuated before the process died. Never
    // replay it; stop and preserve credit/counters for reconciliation.
    if ((int) $database->query("SELECT COUNT(*) FROM hardware_commands WHERE status = 'sent'")->fetchColumn() > 0) { medivendHardwareInterrupt($database); $adapter->stop(); }
    do {
        $ready = medivendWorkerTick($database, $adapter, $config);
        if (in_array('--once', $argv, true)) { echo $ready ? "Hardware health verified.\n" : "Machine unavailable\n"; exit($ready ? 0 : 2); }
        usleep(250000);
    } while (true);
} catch (Throwable $error) {
    if (isset($database)) {
        try { $database->exec("UPDATE hardware_machine_state SET health = 'unavailable' WHERE id = 1"); medivendHardwareInterrupt($database); } catch (Throwable $ignored) {}
    }
    try { $adapter->stop(); } catch (Throwable $ignored) {}
    fwrite(STDERR, 'Machine unavailable. Please contact clinic staff. ' . $error->getMessage() . PHP_EOL); exit(2);
}
