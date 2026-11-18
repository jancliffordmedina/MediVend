<?php
declare(strict_types=1);
/**
 * End-to-end SIMULATION test: PHP worker + MedivendHttpHardwareAdapter + the
 * Python medivend-hwd service with its SIM backend. Proves the integration path
 * (coins -> credit -> motor command -> beam outcome -> stock), not hardware.
 * Needs local MariaDB and Python 3.11+ (MEDIVEND_PYTHON, default py/python3).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../scripts/hardware-worker.php';
require_once __DIR__ . '/../includes/hardware-http-adapter.php';
function dsAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$database = medivendDatabase();
$tables = ['products','transactions','transaction_items','demo_coin_events','hardware_machine_state','hardware_commands','hardware_delivery_events','hardware_unassigned_coins'];
$snapshot = static function () use ($database, $tables): array { $rows = []; foreach ($tables as $t) $rows[$t] = $database->query('SELECT * FROM ' . $t . ' ORDER BY 1')->fetchAll(); return $rows; };
$before = $snapshot();
dsAssert($before['hardware_machine_state'][0]['active_transaction_id'] === null, 'Run on an idle demo test database.');
$failed = false; $owned = []; $productId = null; $python = null; $tag = 'TEST-DS-' . bin2hex(random_bytes(6));
$private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medivend-ds-' . bin2hex(random_bytes(4));
$previous = [];
foreach (['MEDIVEND_MODE','MEDIVEND_HARDWARE_ADAPTER','MEDIVEND_DELIVERY_TIMEOUT_SECONDS'] as $name) $previous[$name] = getenv($name);
try {
    mkdir($private, 0700);
    $used = array_fill_keys($database->query('SELECT compartment_number FROM products')->fetchAll(PDO::FETCH_COLUMN), true);
    for ($slot = 1; isset($used[$slot]); $slot++) {}
    $insert = $database->prepare('INSERT INTO products (sku, name, price_centavos, stock_quantity, compartment_number, is_demo) VALUES (?, ?, 1000, 5, ?, 1)');
    $insert->execute([$tag, 'Device simulation test', $slot]); $productId = (int) $database->lastInsertId();

    // Private service config with fast simulated timings and an isolated journal.
    $port = random_int(30000, 39000); $token = $private . DIRECTORY_SEPARATOR . 'hwd.token';
    file_put_contents($token, bin2hex(random_bytes(32)));
    $toml = $private . DIRECTORY_SEPARATOR . 'hwd.toml';
    $slash = static fn(string $p): string => str_replace('\\', '/', $p);
    file_put_contents($toml, implode("\n", [
        '[service]', 'backend = "sim"', 'port = ' . $port, 'token_file = "' . $slash($token) . '"', 'journal_path = "' . $slash($private) . '/journal.sqlite3"',
        '[coin]', 'pulses = { "1" = 100, "5" = 500, "10" = 1000, "20" = 2000 }', 'glitch_ms = 2', 'pulse_min_ms = 5', 'pulse_max_ms = 30', 'train_gap_ms = 60',
        '[beam]', 'debounce_ms = 2', 'max_block_ms = 250', 'watch_after_return_ms = 250', 'settle_ms = 60',
        '[servo]', 'min_pulse_us = 1000', 'max_pulse_us = 2000',
        '[sim]', 'pulse_ms = 8', 'pulse_gap_ms = 8', 'beam_delay_ms = 30', 'beam_block_ms = 15',
        '[[compartments]]', 'number = ' . $slot, 'channel = 0', 'rest_angle = 0', 'dispense_angle = 90', 'move_ms = 40', 'hold_ms = 10', '',
    ]));
    // Resolve the real interpreter: terminating a launcher (Windows `py`) would orphan its child.
    $launcher = getenv('MEDIVEND_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
    $pythonBinary = trim((string) shell_exec(escapeshellarg($launcher) . ' -c "import sys; print(sys.executable)"'));
    dsAssert($pythonBinary !== '' && is_file($pythonBinary), 'Python 3.11+ is required (set MEDIVEND_PYTHON).');
    $log = $private . DIRECTORY_SEPARATOR . 'hwd.log';
    $python = proc_open([$pythonBinary, '-m', 'medivend_hwd', '--config', $toml, 'serve'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, realpath(__DIR__ . '/../hardware'));
    dsAssert(is_resource($python), 'Cannot start the Python hardware service.'); fclose($pipes[0]);
    register_shutdown_function(static function () use (&$python): void { if (is_resource($python)) { proc_terminate($python); } });

    $adapterFile = $private . DIRECTORY_SEPARATOR . 'adapter.php';
    file_put_contents($adapterFile, '<?php require_once ' . var_export(realpath(__DIR__ . '/../includes/hardware-http-adapter.php'), true) . '; return new MedivendHttpHardwareAdapter(' . var_export('http://127.0.0.1:' . $port, true) . ', ' . var_export($token, true) . ');');
    putenv('MEDIVEND_MODE=device_simulation'); putenv('MEDIVEND_HARDWARE_ADAPTER=' . $adapterFile); putenv('MEDIVEND_DELIVERY_TIMEOUT_SECONDS=10');
    $config = medivendMachineConfig(); $config['adapter_digest'] = hash_file('sha256', $adapterFile);
    $adapter = medivendLoadHardwareAdapter($config);
    $deadline = microtime(true) + 15;
    while (($adapter->health()['connected'] ?? false) !== true) { dsAssert(microtime(true) < $deadline, 'Python service did not become healthy: ' . @file_get_contents($log)); usleep(100000); }

    putenv('MEDIVEND_DELIVERY_TIMEOUT_SECONDS=1');
    dsAssert(($adapter->health()['connected'] ?? null) === false, 'A PHP delivery timeout shorter than the device cycle must refuse readiness.');
    putenv('MEDIVEND_DELIVERY_TIMEOUT_SECONDS=10');
    $realAdapter = new MedivendHttpHardwareAdapter('http://127.0.0.1:' . $port, $token, false);
    dsAssert(($realAdapter->health()['connected'] ?? null) === false, 'Real hardware mode must refuse a simulated service.');
    dsAssert(medivendWorkerTick($database, $adapter, $config) === true, 'Worker should verify simulated device health.');
    $status = medivendMachineStatus();
    dsAssert($status['mode'] === 'device_simulation' && $status['simulated_device'] === true && $status['available'] === true, 'Fresh simulated heartbeat makes the device-simulation machine available.');
    echo "PASS: worker verifies the simulated service; real mode refuses it; status is flagged simulated_device.\n";

    $sim = static function (string $path, array $body) use ($port, $token): void {
        $curl = curl_init('http://127.0.0.1:' . $port . '/v1/' . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . trim(file_get_contents($token))]]);
        $text = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        dsAssert(in_array($code, [200, 202], true), 'Simulator call failed: ' . $path . ' ' . $text);
    };
    $stock = static fn(): int => (int) $database->query('SELECT stock_quantity FROM products WHERE id = ' . $productId)->fetchColumn();
    $runUntil = static function (callable $done, string $what) use ($database, $adapter, $config): void {
        $deadline = microtime(true) + 15;
        while (!$done()) { dsAssert(microtime(true) < $deadline, 'Timed out waiting for ' . $what); medivendWorkerTick($database, $adapter, $config); usleep(100000); }
    };
    $order = static function (int $qty) use ($database, $productId, &$owned): array {
        $key = bin2hex(random_bytes(32)); $saved = medivendSaveOrder($database, [['product_id' => $productId, 'quantity' => $qty]], $key, true, true);
        $owned[] = $saved['order']['reference']; return [$key, $saved['order']['reference']];
    };

    [, $reference] = $order(2); $initial = $stock();
    dsAssert(medivendReadOrder($database, $reference)['is_demo'] === true, 'Device simulation orders are demo orders.');
    $sim('sim/coin', ['centavos' => 1000]); $sim('sim/coin', ['centavos' => 500]);
    $runUntil(static fn() => medivendReadOrder($database, $reference)['amount_paid_centavos'] === 1500, 'two simulated coins');
    dsAssert($stock() === $initial, 'Partial payment never moves stock.');
    $sim('sim/coin', ['centavos' => 500]);
    $runUntil(static fn() => medivendReadOrder($database, $reference)['order_status'] === 'completed', 'paid order to complete');
    $done = medivendReadOrder($database, $reference);
    dsAssert($done['amount_paid_centavos'] === 2000 && $done['items'][0]['dispensed_quantity'] === 2 && $stock() === $initial - 2, 'Exactly two beam-confirmed units deducted two demo stock.');
    $confirmed = $database->prepare("SELECT COUNT(*) FROM hardware_delivery_events e JOIN hardware_commands c ON c.id = e.command_id JOIN transactions t ON t.id = c.transaction_id WHERE t.reference = ? AND e.result = 'confirmed'");
    $confirmed->execute([$reference]); dsAssert((int) $confirmed->fetchColumn() === 2, 'Two confirmed delivery receipts recorded.');
    $database->exec('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1');
    echo "PASS: simulated coins -> partial -> paid -> two servo cycles -> two beam confirmations -> completed, stock -2.\n";

    [, $jamRef] = $order(1); $beforeJam = $stock();
    $sim('sim/beam', ['scenario' => 'jam']); $sim('sim/coin', ['centavos' => 1000]);
    $runUntil(static fn() => medivendReadOrder($database, $jamRef)['order_status'] === 'needs_review', 'jam to require review');
    dsAssert($stock() === $beforeJam && medivendReadOrder($database, $jamRef)['amount_paid_centavos'] === 1000, 'Jam keeps credit, deducts nothing and requires staff.');
    medivendWorkerTick($database, $adapter, $config);
    dsAssert(!medivendMachineStatus()['available'], 'Unresolved review blocks the next customer.');
    $retire = $database->prepare("UPDATE transactions SET order_status = 'failed', dispensing_status = 'failed' WHERE reference = ?"); $retire->execute([$jamRef]);
    $database->exec('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1');
    echo "PASS: simulated jam -> needs_review, credit retained, no stock change, machine blocked.\n";

    [, $timeoutRef] = $order(1); $beforeNone = $stock();
    $sim('sim/beam', ['scenario' => 'none']); $sim('sim/coin', ['centavos' => 1000]);
    $runUntil(static fn() => medivendReadOrder($database, $timeoutRef)['order_status'] === 'needs_review', 'missing beam to require review');
    dsAssert($stock() === $beforeNone, 'Motor movement without a beam passage is not delivery.');
    $retire->execute([$timeoutRef]);
    $database->exec('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1');
    echo "PASS: motor moved but no beam passage -> needs_review, no stock change.\n";
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    if (isset($log) && is_file($log)) fwrite(STDERR, "--- service log (tail) ---\n" . implode("\n", array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -25)) . "\n");
    fwrite(STDERR, '--- unassigned coins: ' . json_encode($database->query('SELECT event_id, reason, observed_at, created_at FROM hardware_unassigned_coins')->fetchAll()) . "\n");
}
finally {
    if (is_resource($python)) { proc_terminate($python); proc_close($python); }
    foreach ($previous as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
    if ($database->inTransaction()) $database->rollBack();
    $database->beginTransaction();
    $database->exec('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1');
    if ($productId) {
        $parked = $database->prepare('DELETE u FROM hardware_unassigned_coins u JOIN transactions t ON t.id = u.candidate_transaction_id JOIN transaction_items i ON i.transaction_id = t.id WHERE i.product_id = ?');
        $parked->execute([$productId]);
    }
    foreach ($owned as $reference) {
        foreach (['DELETE e FROM hardware_delivery_events e JOIN hardware_commands c ON c.id=e.command_id JOIN transactions t ON t.id=c.transaction_id WHERE t.reference=?',
            'DELETE c FROM hardware_commands c JOIN transactions t ON t.id=c.transaction_id WHERE t.reference=?',
            'DELETE e FROM demo_coin_events e JOIN transactions t ON t.id=e.transaction_id WHERE t.reference=?',
            'DELETE i FROM transaction_items i JOIN transactions t ON t.id=i.transaction_id WHERE t.reference=?', 'DELETE FROM transactions WHERE reference=?'] as $sql) {
            $query = $database->prepare($sql); $query->execute([$reference]);
        }
    }
    if ($productId) { $delete = $database->prepare('DELETE FROM products WHERE id = ? AND sku = ?'); $delete->execute([$productId, $tag]); }
    $row = $before['hardware_machine_state'][0];
    $restore = $database->prepare('UPDATE hardware_machine_state SET active_transaction_id=?, health=?, heartbeat_at=?, config_hash=? WHERE id=1');
    $restore->execute([$row['active_transaction_id'], $row['health'], $row['heartbeat_at'], $row['config_hash']]);
    $database->commit();
    if (is_dir($private)) { foreach (glob($private . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file); @rmdir($private); }
    if ($snapshot() !== $before) { $failed = true; fwrite(STDERR, "FAIL: Original database rows changed.\n"); }
    else echo "Only device-simulation fixtures removed; original database rows are unchanged.\n";
}
exit($failed ? 1 : 0);
