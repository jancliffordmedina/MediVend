<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/checkout.php';

function medivendMachineConfig(): array
{
    $config = require __DIR__ . '/../config/machine.php';
    if (!in_array($config['mode'], ['simulation', 'device_simulation', 'hardware'], true)) throw new RuntimeException('Invalid server machine mode.');
    return $config;
}

/** hardware and device_simulation both run the worker, lease and read-only customer flow. */
function medivendHardwareFlow(?array $config = null): bool
{
    return in_array(($config ?? medivendMachineConfig())['mode'], ['hardware', 'device_simulation'], true);
}

/** device_simulation drives the real worker against DEMO inventory/orders only; real
 * hardware only ever touches is_demo = 0 rows. The two are never mixed. */
function medivendHardwareInventoryDemo(?array $config = null): bool
{
    return ($config ?? medivendMachineConfig())['mode'] === 'device_simulation';
}

/** Staff stock-room ledger mode: device simulation restocks test inventory. */
function medivendStockMode(): string
{
    return medivendMachineConfig()['mode'] === 'hardware' ? 'hardware' : 'simulation';
}

function medivendMachineStatus(): array
{
    $status = ['mode' => 'hardware', 'available' => false, 'message' => 'Machine unavailable',
        'accepted_denominations_centavos' => [100, 500, 1000, 2000], 'unit_price_centavos' => 1000,
        'exact_payment_message' => 'Exact payment only. No change.', 'active_order' => false, 'simulated_device' => false];
    try {
        $config = medivendMachineConfig();
        $status['mode'] = $config['mode'];
        $status['simulated_device'] = $config['mode'] === 'device_simulation';
        if ($config['mode'] === 'simulation') return array_replace($status, ['available' => true, 'message' => 'Simulation mode']);
        $database = medivendDatabase();
        $row = $database->query('SELECT active_transaction_id, health, config_hash, TIMESTAMPDIFF(SECOND, heartbeat_at, CURRENT_TIMESTAMP) AS age FROM hardware_machine_state WHERE id = 1')->fetch();
        $status['active_order'] = $row && $row['active_transaction_id'] !== null;
        // A config boolean is never evidence of connected hardware. Only the
        // CLI worker can publish a fresh, verified adapter health observation.
        $configured = is_string($config['adapter_file']) && is_file($config['adapter_file']) && is_readable($config['adapter_file'])
            && $config['delivery_timeout_seconds'] > 0 && $config['delivery_timeout_seconds'] <= 86400;
        $status['available'] = $configured && $row && $row['health'] === 'ready'
            && hash_equals(medivendHardwareConfigHash($config), (string) $row['config_hash'])
            && $row['age'] !== null && (int) $row['age'] >= 0 && (int) $row['age'] <= $config['heartbeat_timeout_seconds'];
        if ($status['available']) {
            $prices = $database->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND is_demo = ? AND price_centavos <> 1000');
            $prices->execute([medivendHardwareInventoryDemo($config) ? 1 : 0]); $badPrices = (int) $prices->fetchColumn();
            if ($badPrices > 0 || medivendHardwareBlockingIssues($database) > 0) $status['available'] = false;
        }
    } catch (Throwable $error) { error_log('[MediVend machine] ' . $error->getMessage()); }
    return $status;
}

/** Anything that must stop new hardware purchases until staff act. */
function medivendHardwareBlockingIssues(PDO $database): int
{
    $sql = "SELECT COUNT(*) FROM transactions t WHERE t.is_demo = ? AND (t.order_status = 'needs_review' OR t.dispensing_status = 'needs_review')";
    // Device simulation shares demo orders with the browser simulation. Only
    // demo orders that went through the device path (a motor command or a
    // device-accepted coin) block it; browser-simulation reviews do not.
    if (medivendHardwareInventoryDemo()) $sql .= " AND (EXISTS (SELECT 1 FROM hardware_commands c WHERE c.transaction_id = t.id)
        OR EXISTS (SELECT 1 FROM demo_coin_events e WHERE e.transaction_id = t.id AND e.event_source = 'accepted_coin'))";
    $query = $database->prepare($sql);
    $query->execute([medivendHardwareInventoryDemo() ? 1 : 0]); $reviews = (int) $query->fetchColumn();
    $coins = (int) $database->query('SELECT COUNT(*) FROM hardware_unassigned_coins WHERE resolved_at IS NULL')->fetchColumn();
    return $reviews + $coins;
}

function medivendHardwareConfigHash(array $config): string
{
    $digest = $config['adapter_digest'] ?? ($config['adapter_file'] && is_readable($config['adapter_file']) ? hash_file('sha256', $config['adapter_file']) : '');
    return hash('sha256', json_encode([$config['mode'],$config['adapter_file'],$config['delivery_timeout_seconds'],$digest], JSON_THROW_ON_ERROR));
}

function medivendRequireSimulation(): void
{
    if (medivendMachineConfig()['mode'] !== 'simulation') {
        throw new MedivendCheckoutError(403, 'simulation_disabled', 'Machine unavailable. Please contact clinic staff.');
    }
}

function medivendRequireHardwareReady(): void
{
    $status = medivendMachineStatus();
    if (!in_array($status['mode'], ['hardware', 'device_simulation'], true) || !$status['available']) {
        throw new MedivendCheckoutError(503, 'machine_unavailable', 'Machine unavailable. Please contact clinic staff.');
    }
}
