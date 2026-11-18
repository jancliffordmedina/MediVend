<?php
declare(strict_types=1);
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/checkout.php';

/*
 * Staff resolution of orders that stopped for review, and of unassigned coins.
 * Staff record what they PHYSICALLY did; the software never claims a refund or
 * delivery on its own. One resolution per order, idempotent by event ID.
 *
 *  refunded    - staff returned the recorded credit by hand. Order: failed / refunded.
 *  handed_over - staff handed over every remaining unit by hand (taken from the
 *                machine's stock, which is reduced now). Requires full payment.
 *                Order: completed / dispensed. Any excess credit is returned by hand.
 *  closed      - nothing was returned or handed over (e.g. a test order). Order: failed.
 */

const MEDIVEND_RESOLUTIONS = ['refunded', 'handed_over', 'closed'];

/** Inventory class staff resolve in this mode (browser and device simulation use demo rows). */
function medivendReviewDemo(): int
{
    return medivendMachineConfig()['mode'] === 'hardware' ? 0 : 1;
}

function medivendReviewNote(mixed $note): string
{
    if (!is_string($note)) throw new MedivendCheckoutError(422, 'invalid_note', 'Add a short note about what you did.');
    $note = trim($note);
    if (strlen($note) < 3 || strlen($note) > 240 || preg_match('/[\x00-\x1f\x7f]/', $note) === 1 || !mb_check_encoding($note, 'UTF-8')) {
        throw new MedivendCheckoutError(422, 'invalid_note', 'Add a note of 3 to 240 characters without line breaks.');
    }
    return $note;
}

function medivendRemainingUnits(array $order): int
{
    return array_sum(array_map(static fn(array $i): int => $i['quantity'] - $i['dispensed_quantity'], $order['items']));
}

/** Money owed back = recorded credit minus the value of units actually delivered. */
function medivendRefundDue(array $order): int
{
    $deliveredValue = array_sum(array_map(static fn(array $i): int => $i['dispensed_quantity'] * $i['unit_price_centavos'], $order['items']));
    return max(0, $order['amount_paid_centavos'] - $deliveredValue);
}

function medivendReadResolution(PDO $database, string $orderId): ?array
{
    $query = $database->prepare('SELECT action, refunded_centavos, units_handed_over, staff_user, created_at FROM order_resolutions WHERE transaction_id = ?');
    $query->execute([$orderId]); $row = $query->fetch();
    if (!$row) return null;
    return ['action' => $row['action'], 'refunded_centavos' => (int) $row['refunded_centavos'], 'units_handed_over' => (int) $row['units_handed_over'],
        'staff_user' => $row['staff_user'], 'created_at' => $row['created_at']];
}

function medivendReviewQueue(PDO $database): array
{
    $demo = medivendReviewDemo();
    $query = $database->prepare("SELECT reference FROM transactions WHERE is_demo = ? AND (order_status = 'needs_review' OR dispensing_status = 'needs_review') ORDER BY created_at, id LIMIT 100");
    $query->execute([$demo]);
    $orders = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $reference) {
        $order = medivendReadOrder($database, $reference);
        $reason = $database->prepare("SELECT reason_code FROM demo_coin_events WHERE transaction_id = ? AND reason_code IS NOT NULL ORDER BY id DESC LIMIT 1");
        $reason->execute([$order['id']]);
        $delivery = $database->prepare("SELECT e.outcome FROM hardware_delivery_events e JOIN hardware_commands c ON c.id = e.command_id WHERE c.transaction_id = ? ORDER BY e.created_at DESC LIMIT 1");
        $delivery->execute([$order['id']]);
        $created = $database->prepare('SELECT created_at FROM transactions WHERE id = ?'); $created->execute([$order['id']]);
        $order['created_at'] = $created->fetchColumn();
        $order['excess_centavos'] = max(0, $order['amount_paid_centavos'] - $order['total_centavos']);
        $order['refund_due_centavos'] = medivendRefundDue($order);
        $order['remaining_units'] = medivendRemainingUnits($order);
        $order['last_delivery_outcome'] = $delivery->fetchColumn() ?: null;
        $order['coin_reason'] = $reason->fetchColumn() ?: null;
        $orders[] = $order;
    }
    $coins = $database->query('SELECT event_id, coin_centavos, reason, observed_at, created_at FROM hardware_unassigned_coins WHERE resolved_at IS NULL ORDER BY created_at LIMIT 100')->fetchAll();
    foreach ($coins as &$coin) $coin['coin_centavos'] = (int) $coin['coin_centavos'];
    $history = $database->prepare('SELECT r.event_id, t.reference, r.action, r.amount_paid_centavos, r.refunded_centavos, r.units_handed_over, r.note, r.staff_user, r.created_at
        FROM order_resolutions r JOIN transactions t ON t.id = r.transaction_id WHERE t.is_demo = ? ORDER BY r.created_at DESC LIMIT 20');
    $history->execute([$demo]);
    $recent = array_map(static function (array $row): array {
        foreach (['amount_paid_centavos', 'refunded_centavos', 'units_handed_over'] as $field) $row[$field] = (int) $row[$field];
        return $row;
    }, $history->fetchAll());
    return ['mode' => medivendMachineConfig()['mode'], 'orders' => $orders, 'unassigned_coins' => $coins, 'recent' => $recent];
}

function medivendResolveOrder(PDO $database, string $eventId, string $reference, string $action, string $note, string $actor): array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $eventId)) throw new MedivendCheckoutError(422, 'invalid_event_id', 'A unique resolution ID is required.');
    if (!preg_match('/\AMV-[A-F0-9]{32}\z/', $reference)) throw new MedivendCheckoutError(422, 'invalid_reference', 'Choose a saved order.');
    if (!in_array($action, MEDIVEND_RESOLUTIONS, true)) throw new MedivendCheckoutError(422, 'invalid_action', 'Choose what was done for this order.');
    $note = medivendReviewNote($note);
    $database->beginTransaction();
    try {
        // Lock order: machine row, then the order (same as checkout/session/worker).
        $database->query('SELECT active_transaction_id FROM hardware_machine_state WHERE id = 1 FOR UPDATE')->fetch();
        $lock = $database->prepare('SELECT id FROM transactions WHERE reference = ? FOR UPDATE'); $lock->execute([$reference]);
        if (!$lock->fetch()) throw new MedivendCheckoutError(404, 'order_not_found', 'That order could not be found.');
        $order = medivendReadOrder($database, $reference);
        $existing = $database->prepare('SELECT event_id, transaction_id, action FROM order_resolutions WHERE event_id = ? OR transaction_id = ?');
        $existing->execute([$eventId, $order['id']]); $previous = $existing->fetch();
        if ($previous) {
            if ($previous['event_id'] === $eventId && (string) $previous['transaction_id'] === $order['id'] && $previous['action'] === $action) {
                $database->commit();
                return ['order' => $order, 'replayed' => true];
            }
            throw new MedivendCheckoutError(409, 'already_resolved', 'This order already has a recorded resolution.');
        }
        if ((int) $order['is_demo'] !== medivendReviewDemo()) throw new MedivendCheckoutError(409, 'wrong_inventory', 'This order belongs to the other machine mode.');
        if ($order['order_status'] !== 'needs_review' && $order['dispensing_status'] !== 'needs_review') {
            throw new MedivendCheckoutError(409, 'not_in_review', 'Only orders waiting for staff review can be resolved.');
        }
        $paid = $order['amount_paid_centavos'];
        $delivered = array_sum(array_column($order['items'], 'dispensed_quantity'));
        $refunded = 0; $handed = 0;
        $remaining = medivendRemainingUnits($order);
        if ($action === 'refunded') {
            // Return only what the customer did not receive: credit minus the
            // value of units already delivered (this also covers excess coins).
            $refunded = medivendRefundDue($order);
            if ($refunded === 0) throw new MedivendCheckoutError(409, 'nothing_to_refund', 'Nothing is owed back for this order. Use another resolution.');
            if ($remaining === 0) {
                // Every unit was delivered; only excess credit was returned.
                $update = $database->prepare("UPDATE transactions SET order_status = 'completed', dispensing_status = 'dispensed', completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP) WHERE id = ?");
                $update->execute([$order['id']]);
            } else {
                $update = $database->prepare("UPDATE transactions SET order_status = 'failed', payment_status = 'refunded', dispensing_status = ? WHERE id = ?");
                $update->execute([$delivered > 0 ? 'partial' : 'failed', $order['id']]);
            }
        } elseif ($action === 'handed_over') {
            if ($remaining === 0) throw new MedivendCheckoutError(409, 'nothing_to_hand_over', 'Every unit was already delivered. Record the returned excess with “Returned the money by hand”.');
            if ($paid < $order['total_centavos']) throw new MedivendCheckoutError(409, 'not_fully_paid', 'Only a fully paid order can be completed by hand.');
            $product = $database->prepare('SELECT stock_quantity, is_demo FROM products WHERE id = ? FOR UPDATE');
            $stock = $database->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
            $item = $database->prepare('UPDATE transaction_items SET dispensed_quantity = quantity WHERE transaction_id = ? AND product_id = ?');
            foreach ($order['items'] as $line) {
                $remaining = $line['quantity'] - $line['dispensed_quantity'];
                if ($remaining <= 0) continue;
                $product->execute([$line['product_id']]); $row = $product->fetch();
                if (!$row || (int) $row['stock_quantity'] < $remaining) {
                    throw new MedivendCheckoutError(409, 'insufficient_stock', $line['product_name'] . ' has only ' . (int) ($row['stock_quantity'] ?? 0) . ' recorded units. Restock or choose another resolution.');
                }
                $stock->execute([$remaining, $line['product_id'], $remaining]);
                $item->execute([$order['id'], $line['product_id']]);
                $handed += $remaining;
            }
            $refunded = max(0, $paid - $order['total_centavos']); // excess returned by hand
            $update = $database->prepare("UPDATE transactions SET order_status = 'completed', dispensing_status = 'dispensed', completed_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$order['id']]);
        } else {
            $update = $database->prepare("UPDATE transactions SET order_status = 'failed', dispensing_status = ? WHERE id = ?");
            $update->execute([$delivered > 0 ? 'partial' : 'failed', $order['id']]);
        }
        $commands = $database->prepare("UPDATE hardware_commands SET status = 'needs_review' WHERE transaction_id = ? AND status IN ('queued', 'sent')");
        $commands->execute([$order['id']]);
        $insert = $database->prepare('INSERT INTO order_resolutions (event_id, transaction_id, action, amount_paid_centavos, refunded_centavos, units_handed_over,
            previous_order_status, previous_payment_status, previous_dispensing_status, note, staff_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$eventId, $order['id'], $action, $paid, $refunded, $handed, $order['order_status'], $order['payment_status'], $order['dispensing_status'], $note, $actor]);
        $release = $database->prepare('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1 AND active_transaction_id = ?');
        $release->execute([$order['id']]);
        $result = medivendReadOrder($database, $reference);
        $database->commit();
        return ['order' => $result, 'replayed' => false];
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

function medivendResolveCoin(PDO $database, string $coinEventId, string $action, string $note, string $actor): void
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $coinEventId)) throw new MedivendCheckoutError(422, 'invalid_event_id', 'Choose an unassigned coin.');
    if (!in_array($action, ['refunded', 'closed'], true)) throw new MedivendCheckoutError(422, 'invalid_action', 'Choose what was done with this coin.');
    $note = medivendReviewNote($note);
    $update = $database->prepare('UPDATE hardware_unassigned_coins SET resolved_at = CURRENT_TIMESTAMP, resolved_by = ?, resolution = ?, resolution_note = ? WHERE event_id = ? AND resolved_at IS NULL');
    $update->execute([$actor, $action, $note, $coinEventId]);
    if ($update->rowCount() === 1) return;
    $check = $database->prepare('SELECT resolution FROM hardware_unassigned_coins WHERE event_id = ?'); $check->execute([$coinEventId]);
    $current = $check->fetchColumn();
    if ($current === false) throw new MedivendCheckoutError(404, 'coin_not_found', 'That coin record could not be found.');
    if ($current !== $action) throw new MedivendCheckoutError(409, 'already_resolved', 'This coin already has a recorded resolution.');
}
