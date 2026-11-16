<?php
declare(strict_types=1);
require_once __DIR__ . '/staff.php';

function medivendStockEvent(array $row): array
{
    foreach (['product_id', 'compartment_number', 'quantity', 'stock_before', 'stock_after'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    return $row;
}

function medivendStockView(PDO $database, string $mode): array
{
    if (!in_array($mode, ['simulation', 'hardware'], true)) throw new RuntimeException('Invalid stock mode.');
    $demo = $mode === 'simulation' ? 1 : 0;
    $query = $database->prepare('SELECT id, sku, name, compartment_number, stock_quantity, is_active, is_demo, price_centavos
        FROM products WHERE is_demo = ? ORDER BY compartment_number, id');
    $query->execute([$demo]);
    $products = [];
    foreach ($query as $row) {
        foreach (['id', 'compartment_number', 'stock_quantity', 'price_centavos'] as $field) $row[$field] = (int) $row[$field];
        $row['is_active'] = (bool) $row['is_active']; $row['is_demo'] = (bool) $row['is_demo'];
        $products[] = $row;
    }
    $query = $database->prepare('SELECT event_id, product_id, product_name, sku, compartment_number, mode,
        quantity, stock_before, stock_after, note, staff_user, created_at
        FROM staff_stock_events WHERE mode = ? ORDER BY created_at DESC, event_id DESC LIMIT 20');
    $query->execute([$mode]);
    return ['mode' => $mode, 'products' => $products,
        'history' => array_map('medivendStockEvent', $query->fetchAll())];
}

function medivendStockPayload(stdClass $payload): array
{
    if (!medivendStaffKeys($payload, ['event_id', 'product_id', 'quantity', 'note'])
        || !is_string($payload->event_id) || preg_match('/\A[a-f0-9]{64}\z/', $payload->event_id) !== 1
        || !is_int($payload->product_id) || $payload->product_id < 1
        || !is_int($payload->quantity) || $payload->quantity < 1 || $payload->quantity > 10000
        || !is_string($payload->note)) {
        throw new MedivendCheckoutError(422, 'invalid_restock', 'Choose a medicine, an integer quantity from 1 to 10,000, and a restock note.');
    }
    $note = trim($payload->note);
    if (!preg_match('//u', $note) || strlen($note) < 3 || strlen($note) > 240 || preg_match('/[\x00-\x1f\x7f]/', $note)) {
        throw new MedivendCheckoutError(422, 'invalid_note', 'Enter a restock note of 3 to 240 bytes without line breaks.');
    }
    return ['event_id' => $payload->event_id, 'product_id' => $payload->product_id,
        'quantity' => $payload->quantity, 'note' => $note];
}

function medivendStockReplay(array $event, array $request, string $actor, string $mode): array
{
    if ((int) $event['product_id'] !== $request['product_id'] || (int) $event['quantity'] !== $request['quantity']
        || $event['note'] !== $request['note'] || $event['staff_user'] !== $actor || $event['mode'] !== $mode) {
        throw new MedivendCheckoutError(409, 'restock_event_conflict', 'This restock request was already used with different details. Refresh history before starting another restock.');
    }
    return ['replayed' => true, 'restock' => medivendStockEvent($event)];
}

/** Server-only: API supplies the authenticated actor and current server mode. */
function medivendAddStock(PDO $database, array $request, string $actor, string $mode): array
{
    if (!in_array($mode, ['simulation', 'hardware'], true)) throw new RuntimeException('Invalid stock mode.');
    $request = medivendStockPayload((object) $request);
    if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}\z/', $actor) !== 1) throw new RuntimeException('Invalid staff actor.');
    $find = $database->prepare('SELECT * FROM staff_stock_events WHERE event_id = ?');
    $find->execute([$request['event_id']]);
    if ($row = $find->fetch()) return medivendStockReplay($row, $request, $actor, $mode);
    $database->beginTransaction();
    try {
        // The same product-row lock used by dispensing prevents lost additions.
        $productQuery = $database->prepare('SELECT id, sku, name, compartment_number, stock_quantity, is_active, is_demo
            FROM products WHERE id = ? FOR UPDATE');
        $productQuery->execute([$request['product_id']]);
        $product = $productQuery->fetch();
        // This is the transaction's first consistent read, after the product
        // lock. No gap lock is taken for an event that does not exist yet.
        $lockedFind = $database->prepare('SELECT * FROM staff_stock_events WHERE event_id = ?');
        $lockedFind->execute([$request['event_id']]);
        if ($row = $lockedFind->fetch()) {
            $result = medivendStockReplay($row, $request, $actor, $mode);
            $database->commit();
            return $result;
        }
        if (!$product || !(int) $product['is_active'] || (bool) $product['is_demo'] !== ($mode === 'simulation')) {
            throw new MedivendCheckoutError(409, 'product_unavailable', 'This medicine is inactive or does not belong to the current machine mode.');
        }
        $before = (int) $product['stock_quantity'];
        $maxStock = min(PHP_INT_MAX, 4294967295);
        if ($before < 0 || $before > $maxStock - $request['quantity']) {
            throw new MedivendCheckoutError(422, 'stock_limit', 'This addition would exceed the supported stock limit.');
        }
        $after = $before + $request['quantity'];
        $insert = $database->prepare('INSERT INTO staff_stock_events
            (event_id, product_id, product_name, sku, compartment_number, mode, quantity, stock_before, stock_after, note, staff_user)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$request['event_id'], $product['id'], $product['name'], $product['sku'],
            $product['compartment_number'], $mode, $request['quantity'], $before, $after, $request['note'], $actor]);
        $update = $database->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?');
        $update->execute([$request['quantity'], $product['id']]);
        $lockedFind->execute([$request['event_id']]);
        $event = $lockedFind->fetch();
        $database->commit();
        return ['replayed' => false, 'restock' => medivendStockEvent($event)];
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollBack();
        // Different product locks can race to claim one globally unique ID.
        if ($error instanceof PDOException && $error->getCode() === '23000') {
            $find->execute([$request['event_id']]);
            if ($row = $find->fetch()) return medivendStockReplay($row, $request, $actor, $mode);
        }
        throw $error;
    }
}
