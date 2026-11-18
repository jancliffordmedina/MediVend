<?php
declare(strict_types=1);
require_once __DIR__ . '/machine.php';
require_once __DIR__ . '/accepted-coin.php';
require_once __DIR__ . '/hardware-adapter.php';

function medivendHardwareReview(PDO $database, string $orderId): void
{
    $update = $database->prepare("UPDATE transactions SET order_status = 'needs_review', dispensing_status = 'needs_review' WHERE id = ?");
    $update->execute([$orderId]);
    $commands = $database->prepare("UPDATE hardware_commands SET status = 'needs_review' WHERE transaction_id = ? AND status IN ('queued','sent')");
    $commands->execute([$orderId]);
}

function medivendQueueHardwareUnit(PDO $database, string $reference): ?array
{
    $database->beginTransaction();
    try {
        $lock = $database->prepare('SELECT id FROM transactions WHERE reference = ? FOR UPDATE'); $lock->execute([$reference]);
        if (!$lock->fetch()) throw new RuntimeException('Unknown hardware order.');
        $order = medivendReadOrder($database, $reference); $demo = medivendHardwareInventoryDemo();
        if ($order['is_demo'] !== $demo || $order['payment_status'] !== 'paid' || $order['amount_paid_centavos'] !== $order['total_centavos']
            || !in_array($order['order_status'], ['pending','processing'], true)) { $database->commit(); return null; }
        // Recheck all remaining stock before the first command. Subsequent
        // units are checked here again and when sensor confirmation commits.
        $query = $database->prepare('SELECT stock_quantity, is_active, is_demo FROM products WHERE id = ? FOR UPDATE');
        foreach ($order['items'] as $item) {
            $query->execute([$item['product_id']]); $product = $query->fetch();
            if (!$product || (int) $product['is_demo'] !== (int) $demo || !(int) $product['is_active']
                || (int) $product['stock_quantity'] < $item['quantity'] - $item['dispensed_quantity']) {
                medivendHardwareReview($database, $order['id']); $database->commit(); return null;
            }
        }
        foreach ($order['items'] as $item) {
            if ($item['dispensed_quantity'] >= $item['quantity']) continue;
            $unit = $item['dispensed_quantity'] + 1;
            $id = hash('sha256', 'medivend-unit-v1:' . $reference . ':' . $item['product_id'] . ':' . $unit);
            $exists = $database->prepare('SELECT id FROM hardware_commands WHERE id = ?'); $exists->execute([$id]);
            if (!$exists->fetch()) {
                $insert = $database->prepare('INSERT INTO hardware_commands (id, transaction_id, product_id, unit_number, compartment_number)
                    SELECT ?, transaction_id, product_id, ?, compartment_number FROM transaction_items WHERE transaction_id = ? AND product_id = ?');
                $insert->execute([$id, $unit, $order['id'], $item['product_id']]);
            }
            $update = $database->prepare("UPDATE transactions SET order_status = 'processing', dispensing_status = IF(dispensing_status = 'partial', 'partial', 'in_progress') WHERE id = ?");
            $update->execute([$order['id']]);
            $get = $database->prepare('SELECT * FROM hardware_commands WHERE id = ?'); $get->execute([$id]); $command = $get->fetch();
            $database->commit(); return $command;
        }
        $database->commit(); return null;
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

function medivendClaimHardwareCommand(PDO $database, string $id, int $timeout): bool
{
    if ($timeout < 1 || $timeout > 86400) throw new RuntimeException('Verified delivery timeout required.');
    // Claim BEFORE I/O. A crash/uncertain dispatch is never automatically sent
    // again. Device-side persistent command deduplication is also mandatory.
    $owner = $database->prepare('SELECT transaction_id FROM hardware_commands WHERE id = ?'); $owner->execute([$id]); $orderId = $owner->fetchColumn();
    $database->beginTransaction();
    try {
        $lock = $database->prepare('SELECT order_status, payment_status, amount_paid_centavos, total_centavos FROM transactions WHERE id = ? FOR UPDATE'); $lock->execute([$orderId]); $order = $lock->fetch();
        $claimed = false;
        if ($order && $order['order_status'] === 'processing' && $order['payment_status'] === 'paid' && $order['amount_paid_centavos'] === $order['total_centavos']) {
            $claim = $database->prepare("UPDATE hardware_commands SET status = 'sent', deadline_at = TIMESTAMPADD(SECOND, ?, CURRENT_TIMESTAMP) WHERE id = ? AND status = 'queued'");
            $claim->execute([$timeout, $id]); $claimed = $claim->rowCount() === 1;
        }
        $database->commit(); return $claimed;
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

function medivendHardwareDelivery(PDO $database, string $eventId, string $commandId, string $outcome): array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $eventId) || !preg_match('/\A[a-f0-9]{64}\z/', $commandId)
        || !in_array($outcome, ['sensor_confirmed','jam','timeout','uncertain'], true)) throw new RuntimeException('Invalid verified sensor event.');
    $owner = $database->prepare('SELECT transaction_id FROM hardware_commands WHERE id = ?'); $owner->execute([$commandId]); $orderId = $owner->fetchColumn();
    if (!$orderId) throw new RuntimeException('Unknown command; preserve sensor event for staff.');
    $database->beginTransaction();
    try {
        // Order first, then command/product: consistent with queue/review locks.
        $lock = $database->prepare('SELECT reference FROM transactions WHERE id = ? FOR UPDATE'); $lock->execute([$orderId]);
        $order = medivendReadOrder($database, $lock->fetchColumn());
        $demo = medivendHardwareInventoryDemo();
        if ($order['is_demo'] !== $demo) throw new RuntimeException('Order inventory class does not match the machine mode.');
        $existing = $database->prepare('SELECT * FROM hardware_delivery_events WHERE event_id = ?'); $existing->execute([$eventId]); $event = $existing->fetch();
        if ($event) {
            if ($event['command_id'] !== $commandId || $event['outcome'] !== $outcome) throw new RuntimeException('Sensor event ID conflict.');
            $database->commit(); return $event + ['replayed' => true];
        }
        $get = $database->prepare('SELECT *, deadline_at < CURRENT_TIMESTAMP AS expired FROM hardware_commands WHERE id = ? FOR UPDATE');
        $get->execute([$commandId]); $command = $get->fetch();
        $result = 'needs_review';
        if ($command['status'] === 'confirmed' && $outcome === 'sensor_confirmed') $result = 'duplicate_command';
        elseif ($outcome === 'sensor_confirmed' && $command['status'] === 'sent' && $command['deadline_at'] !== null && !(bool) $command['expired']
            && $order['order_status'] === 'processing' && $order['payment_status'] === 'paid' && $order['amount_paid_centavos'] === $order['total_centavos']) {
            $item = $database->prepare('SELECT quantity, dispensed_quantity FROM transaction_items WHERE transaction_id = ? AND product_id = ? FOR UPDATE');
            $item->execute([$orderId, $command['product_id']]); $counts = $item->fetch();
            $product = $database->prepare('SELECT stock_quantity, is_active, is_demo FROM products WHERE id = ? FOR UPDATE');
            $product->execute([$command['product_id']]); $stock = $product->fetch();
            if ($counts && $counts['dispensed_quantity'] + 1 === (int) $command['unit_number'] && $counts['dispensed_quantity'] < $counts['quantity']
                && $stock && (int) $stock['is_demo'] === (int) $demo && (int) $stock['is_active'] === 1 && (int) $stock['stock_quantity'] > 0) {
                $decrease = $database->prepare('UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = ? AND stock_quantity > 0'); $decrease->execute([$command['product_id']]);
                $increase = $database->prepare('UPDATE transaction_items SET dispensed_quantity = dispensed_quantity + 1 WHERE transaction_id = ? AND product_id = ? AND dispensed_quantity < quantity'); $increase->execute([$orderId, $command['product_id']]);
                if ($decrease->rowCount() !== 1 || $increase->rowCount() !== 1) throw new RuntimeException('Sensor confirmation failed atomically.');
                $done = $database->prepare("UPDATE hardware_commands SET status = 'confirmed' WHERE id = ?"); $done->execute([$commandId]);
                $remaining = $database->prepare('SELECT SUM(quantity - dispensed_quantity) FROM transaction_items WHERE transaction_id = ?'); $remaining->execute([$orderId]);
                $complete = (int) $remaining->fetchColumn() === 0;
                $update = $database->prepare('UPDATE transactions SET order_status = ?, dispensing_status = ?, completed_at = ' . ($complete ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = ?');
                $update->execute([$complete ? 'completed' : 'processing', $complete ? 'dispensed' : 'partial', $orderId]); $result = 'confirmed';
            }
        }
        if ($result === 'needs_review') medivendHardwareReview($database, (string) $orderId);
        $insert = $database->prepare('INSERT INTO hardware_delivery_events (event_id, command_id, outcome, result) VALUES (?, ?, ?, ?)'); $insert->execute([$eventId, $commandId, $outcome, $result]);
        $database->commit(); return ['event_id' => $eventId, 'command_id' => $commandId, 'outcome' => $outcome, 'result' => $result, 'replayed' => false];
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

/** Seconds of clock tolerance between the device observation and order creation. */
const MEDIVEND_COIN_BIND_TOLERANCE_SECONDS = 2;

/**
 * Record a device-accepted coin. The device does not know which order is open,
 * so the coin is bound to the machine's active order while holding the machine
 * row lock (same lock order as checkout and session close: machine, then order).
 * A coin that cannot be bound is retained in hardware_unassigned_coins and
 * blocks the machine for staff, instead of throwing (which would crash-loop the
 * worker on the same unacknowledged event). Replays of either outcome are safe.
 *
 * Event: event_id (64 hex), coin_centavos, observed_at (unix seconds, from the
 * device journal), optional legacy reference.
 */
function medivendRecordHardwareCoin(PDO $database, array $event): array
{
    $eventId = $event['event_id'] ?? null; $coin = $event['coin_centavos'] ?? null;
    $observedAt = $event['observed_at'] ?? null; $reference = $event['reference'] ?? null;
    if (!is_string($eventId) || !preg_match('/\A[a-f0-9]{64}\z/', $eventId) || !is_int($coin)
        || !in_array($coin, MEDIVEND_COIN_DENOMINATIONS, true)
        || ($observedAt !== null && (!is_int($observedAt) || $observedAt < 0))
        || ($reference !== null && !is_string($reference)) || ($reference === null && $observedAt === null)) {
        throw new RuntimeException('Invalid verified coin event; preserve it in the device journal.');
    }
    $demo = medivendHardwareInventoryDemo() ? 1 : 0;
    $database->beginTransaction();
    try {
        $machine = $database->query('SELECT active_transaction_id FROM hardware_machine_state WHERE id = 1 FOR UPDATE')->fetch();
        if (!$machine) throw new RuntimeException('Hardware machine state is missing.');
        $parked = $database->prepare('SELECT event_id, coin_centavos, reason FROM hardware_unassigned_coins WHERE event_id = ?');
        $parked->execute([$eventId]);
        if ($row = $parked->fetch()) {
            if ((int) $row['coin_centavos'] !== $coin) throw new RuntimeException('Unassigned coin event ID conflict.');
            $database->commit();
            return ['result' => 'unassigned', 'reason' => $row['reason'], 'replayed' => true];
        }
        $known = medivendReadCoinEvent($database, $eventId);
        $candidate = null; $reason = null;
        if ($known) {
            // Already credited earlier (acknowledgement was lost): replay on that order.
            $owner = $database->prepare('SELECT reference FROM transactions WHERE id = ?'); $owner->execute([$known['transaction_id']]);
            $reference = (string) $owner->fetchColumn();
        } elseif ($reference === null) {
            if ($machine['active_transaction_id'] === null) $reason = 'no_active_order';
            else {
                $order = $database->prepare('SELECT id, reference, is_demo, UNIX_TIMESTAMP(created_at) AS created FROM transactions WHERE id = ? FOR UPDATE');
                $order->execute([$machine['active_transaction_id']]); $active = $order->fetch();
                $candidate = $active ? (string) $active['id'] : null;
                if (!$active) $reason = 'order_not_found';
                elseif ((int) $active['is_demo'] !== $demo) $reason = 'not_hardware_order';
                elseif ($observedAt < (int) $active['created'] - MEDIVEND_COIN_BIND_TOLERANCE_SECONDS) $reason = 'before_order';
                else $reference = $active['reference'];
            }
        } else {
            $order = $database->prepare('SELECT id, is_demo FROM transactions WHERE reference = ?'); $order->execute([$reference]); $target = $order->fetch();
            if (!$target) $reason = 'order_not_found';
            elseif ((int) $target['is_demo'] !== $demo) { $reason = 'not_hardware_order'; $candidate = (string) $target['id']; }
        }
        if ($reason !== null) {
            $insert = $database->prepare('INSERT INTO hardware_unassigned_coins (event_id, coin_centavos, reason, candidate_transaction_id, observed_at)
                VALUES (?, ?, ?, ?, ' . ($observedAt === null ? 'NULL' : 'FROM_UNIXTIME(?)') . ')');
            $insert->execute(array_merge([$eventId, $coin, $reason, $candidate], $observedAt === null ? [] : [$observedAt]));
            error_log('[MediVend hardware] Accepted coin retained as unassigned (' . $reason . '): ' . $eventId);
            $database->commit();
            return ['result' => 'unassigned', 'reason' => $reason, 'replayed' => false];
        }
        $recorded = medivendRecordAcceptedCoinLocked($database, $reference, $eventId, $coin);
        $database->commit();
        return ['result' => 'credited', 'reference' => $reference, 'replayed' => $recorded['coin_event']['replayed']];
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

function medivendHardwareInterrupt(PDO $database): void
{
    $database->beginTransaction();
    try {
        $machine = $database->query('SELECT active_transaction_id FROM hardware_machine_state WHERE id = 1 FOR UPDATE')->fetch();
        if ($machine && $machine['active_transaction_id']) {
            $lock = $database->prepare('SELECT id, order_status, amount_paid_centavos FROM transactions WHERE id = ? FOR UPDATE'); $lock->execute([$machine['active_transaction_id']]); $order = $lock->fetch();
            if ($order && $order['order_status'] !== 'completed' && ((int) $order['amount_paid_centavos'] > 0 || $order['order_status'] === 'processing')) medivendHardwareReview($database, (string) $order['id']);
        }
        $database->commit();
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}
