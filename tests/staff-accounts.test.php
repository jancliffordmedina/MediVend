<?php
declare(strict_types=1);
// CLI-only. Uses a new random database; never touches medivend_db rows.
// Covers user accounts, one-sign-in-per-user, roles, bootstrap hand-off, and
// staff resolution of stopped orders / unassigned coins (kiosk unblocking).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/checkout.php';
require_once __DIR__ . '/../includes/database.php';

function acExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

function acRequest(string $base, string $cookie, string $route, ?array $body = null, ?string $csrf = null, array $headers = []): array
{
    $curl = curl_init($base . '/api/' . $route . '.php');
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    if ($csrf !== null) $headers[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 2]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body === [] ? new stdClass() : $body, JSON_THROW_ON_ERROR)); }
    $text = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    acExpect(is_string($text), 'HTTP request failed: ' . $error);
    return ['status' => $status, 'body' => json_decode($text, true) ?? ['raw' => $text]];
}

function acLogin(string $base, string $cookie, string $username, string $password): array
{
    $state = acRequest($base, $cookie, 'staff-session');
    return acRequest($base, $cookie, 'staff-session', ['action' => 'login', 'username' => $username, 'password' => $password], $state['body']['csrf_token']);
}

$name = 'medivend_accttest_' . bin2hex(random_bytes(6));
$folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medivend-accttest-' . bin2hex(random_bytes(6));
$originalName = getenv('MEDIVEND_DB_NAME'); $admin = null; $created = false; $failed = false; $server = null;
try {
    acExpect(extension_loaded('curl'), 'PHP curl is required.');
    mkdir($folder, 0700); mkdir($folder . '/sessions', 0700);
    $admin = medivendDatabase();
    $admin->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $bootPassword = bin2hex(random_bytes(10));
    $environment = array_merge(getenv(), ['MEDIVEND_DB_NAME' => $name, 'MEDIVEND_MODE' => 'simulation', 'MEDIVEND_STAFF_USERNAME' => 'setup-admin',
        'MEDIVEND_STAFF_PASSWORD_HASH' => password_hash($bootPassword, PASSWORD_DEFAULT), 'MEDIVEND_STAFF_RATE_DIR' => $folder . '/rate']);
    $child = proc_open([PHP_BINARY, __DIR__ . '/../scripts/migrate.php'], [0 => ['pipe', 'r'], 1 => ['file', $folder . '/migrate.log', 'a'], 2 => ['file', $folder . '/migrate.log', 'a']], $pipes, null, $environment);
    fclose($pipes[0]); acExpect(proc_close($child) === 0, 'Migration failed: ' . @file_get_contents($folder . '/migrate.log'));
    putenv('MEDIVEND_DB_NAME=' . $name); $database = medivendDatabase();

    $listener = stream_socket_server('tcp://127.0.0.1:0'); $address = stream_socket_get_name($listener, false); fclose($listener);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $folder . '/sessions', '-S', $address, '-t', realpath(__DIR__ . '/..')],
        [0 => ['pipe', 'r'], 1 => ['file', $folder . '/server.log', 'a'], 2 => ['file', $folder . '/server.log', 'a']], $pipes, null, $environment);
    fclose($pipes[0]); $base = 'http://' . $address;
    for ($try = 0; $try < 60 && acRequest($base, $folder . '/probe.cookie', 'staff-session')['status'] === 0; $try++) usleep(50000);

    // -- Bootstrap hand-off -----------------------------------------------------
    $boot = $folder . '/boot.cookie';
    $login = acLogin($base, $boot, 'setup-admin', $bootPassword);
    acExpect($login['status'] === 200 && $login['body']['bootstrap'] === true && $login['body']['role'] === 'admin', 'Bootstrap admin signs in while no admin account exists: ' . json_encode($login));
    $adminPassword = bin2hex(random_bytes(10));
    $make = acRequest($base, $boot, 'staff-users', ['action' => 'create', 'username' => 'nurse.admin', 'display_name' => 'Clinic Admin', 'role' => 'admin', 'password' => $adminPassword], $login['body']['csrf_token']);
    acExpect($make['status'] === 200 && $make['body']['created']['username'] === 'nurse.admin', 'Bootstrap admin creates the first administrator: ' . json_encode($make));
    $after = acRequest($base, $boot, 'staff-session');
    acExpect($after['body']['authenticated'] === false && $after['body']['notice'] === 'bootstrap_ended', 'Bootstrap session ends once an administrator exists.');
    acExpect(acLogin($base, $folder . '/boot2.cookie', 'setup-admin', $bootPassword)['status'] === 401, 'Bootstrap account can no longer sign in.');
    echo "PASS: one-time setup account creates the first administrator, then stops working.\n";

    // -- CLI account creation ---------------------------------------------------
    $staffPassword = bin2hex(random_bytes(10));
    $cli = proc_open([PHP_BINARY, __DIR__ . '/../scripts/create-user.php', '--username', 'desk.staff', '--name', 'Desk Staff', '--role', 'staff', '--password-stdin'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    fwrite($pipes[0], $staffPassword . "\n" . $staffPassword . "\n"); fclose($pipes[0]);
    $cliOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    acExpect(proc_close($cli) === 0 && strpos($cliOut, $staffPassword) === false, 'CLI creates a staff account without echoing the password: ' . $cliOut);
    $dup = proc_open([PHP_BINARY, __DIR__ . '/../scripts/create-user.php', '--username', 'DESK.staff', '--role', 'staff', '--password-stdin'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    fwrite($pipes[0], $staffPassword . "\n" . $staffPassword . "\n"); fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    acExpect(proc_close($dup) !== 0, 'Usernames are unique regardless of letter case.');
    echo "PASS: command-line user creation, hidden password, case-insensitive unique usernames.\n";

    // -- One sign-in per user ---------------------------------------------------
    $tabletA = $folder . '/a.cookie'; $tabletB = $folder . '/b.cookie';
    $a = acLogin($base, $tabletA, 'nurse.admin', $adminPassword);
    acExpect($a['status'] === 200 && $a['body']['role'] === 'admin' && $a['body']['display_name'] === 'Clinic Admin', 'Administrator signs in.');
    $b = acLogin($base, $tabletB, 'nurse.admin', $adminPassword);
    acExpect($b['status'] === 200, 'Second device signs in.');
    $old = acRequest($base, $tabletA, 'staff-session');
    acExpect($old['body']['authenticated'] === false && $old['body']['notice'] === 'signed_in_elsewhere', 'Signing in elsewhere ends the earlier session.');
    acExpect(acRequest($base, $tabletA, 'staff-reviews')['status'] === 401, 'Ended session cannot use staff APIs.');
    echo "PASS: one active sign-in per user; the older device is signed out with a clear notice.\n";

    // -- Roles and admin guards -------------------------------------------------
    $desk = $folder . '/desk.cookie';
    $s = acLogin($base, $desk, 'desk.staff', $staffPassword);
    acExpect($s['status'] === 200 && $s['body']['role'] === 'staff', 'Staff signs in.');
    acExpect(acRequest($base, $desk, 'staff-users')['status'] === 403, 'Staff cannot manage users.');
    acExpect(acRequest($base, $desk, 'staff-reviews')['status'] === 200 && acRequest($base, $desk, 'staff-stock')['status'] === 200, 'Staff can use reviews and stock.');
    $csrf = $b['body']['csrf_token'];
    $users = acRequest($base, $tabletB, 'staff-users')['body']['users'];
    $adminId = null; $staffId = null; foreach ($users as $u) { if ($u['username'] === 'nurse.admin') $adminId = $u['id']; if ($u['username'] === 'desk.staff') $staffId = $u['id']; }
    acExpect(acRequest($base, $tabletB, 'staff-users', ['action' => 'update', 'id' => $adminId, 'is_active' => false], $csrf)['status'] === 409, 'Admins cannot deactivate themselves.');
    acExpect(acRequest($base, $tabletB, 'staff-users', ['action' => 'update', 'id' => $adminId, 'role' => 'staff'], $csrf)['status'] === 409, 'Admins cannot remove their own admin role.');
    acExpect(acRequest($base, $tabletB, 'staff-users', ['action' => 'create', 'username' => 'x', 'display_name' => 'X', 'role' => 'staff', 'password' => 'short'], $csrf)['status'] === 422, 'Weak/invalid accounts are rejected.');
    acExpect(acRequest($base, $tabletB, 'staff-users', ['action' => 'update', 'id' => $staffId, 'is_active' => false], $csrf)['status'] === 200, 'Admin deactivates staff.');
    acExpect(acRequest($base, $desk, 'staff-stock')['status'] === 401 && acLogin($base, $folder . '/desk2.cookie', 'desk.staff', $staffPassword)['status'] === 401, 'Deactivated staff is signed out and cannot sign in.');
    acExpect(acRequest($base, $tabletB, 'staff-users', ['action' => 'update', 'id' => $staffId, 'is_active' => true, 'password' => $staffPassword . 'x'], $csrf)['status'] === 200, 'Admin reactivates with a new password.');
    acExpect(acLogin($base, $folder . '/desk3.cookie', 'desk.staff', $staffPassword . 'x')['status'] === 200, 'Staff signs in with the new password.');
    echo "PASS: roles, self-protection, validation, deactivation and password reset.\n";

    // -- Resolving stopped orders (unblocks the kiosk) ---------------------------
    $database->exec("INSERT INTO products (sku, name, price_centavos, stock_quantity, compartment_number, is_demo) VALUES ('ACCT-A', 'Test medicine', 1000, 5, 1, 1)");
    $productId = (int) $database->lastInsertId();
    $stuck = static function (int $qty, int $paid, int $delivered) use ($database, $productId): array {
        $key = bin2hex(random_bytes(32));
        $order = medivendSaveOrder($database, [['product_id' => $productId, 'quantity' => $qty]], $key, true)['order'];
        $update = $database->prepare("UPDATE transactions SET amount_paid_centavos = ?, payment_status = ?, order_status = 'needs_review', dispensing_status = 'needs_review' WHERE reference = ?");
        $update->execute([$paid, $paid >= $qty * 1000 ? 'paid' : ($paid ? 'partial' : 'pending'), $order['reference']]);
        $items = $database->prepare('UPDATE transaction_items SET dispensed_quantity = ? WHERE transaction_id = ?'); $items->execute([$delivered, $order['id']]);
        return [$key, $order['reference']];
    };
    [$kioskKey, $refundRef] = $stuck(3, 3000, 0);
    $status = acRequest($base, $folder . '/kiosk.cookie', 'demo-order', null, null, ['X-Demo-Order-Key: ' . $kioskKey]);
    acExpect($status['status'] === 200 && $status['body']['resolution'] === null && $status['body']['order']['order_status'] === 'needs_review', 'Kiosk sees the stopped order before staff act.');
    $queue = acRequest($base, $tabletB, 'staff-reviews');
    acExpect(count(array_filter($queue['body']['orders'], static fn($o) => $o['reference'] === $refundRef)) === 1, 'Stopped order appears under Needs attention.');
    $eventId = bin2hex(random_bytes(32));
    $resolve = ['kind' => 'order', 'event_id' => $eventId, 'reference' => $refundRef, 'action' => 'refunded', 'note' => 'Returned PHP 30 cash to student'];
    $done = acRequest($base, $tabletB, 'staff-reviews', $resolve, $csrf);
    acExpect($done['status'] === 200 && $done['body']['resolved']['replayed'] === false, 'Staff records a hand refund: ' . json_encode($done));
    acExpect(acRequest($base, $tabletB, 'staff-reviews', $resolve, $csrf)['body']['resolved']['replayed'] === true, 'Retrying the same resolution is safe.');
    acExpect(acRequest($base, $tabletB, 'staff-reviews', array_replace($resolve, ['event_id' => bin2hex(random_bytes(32)), 'action' => 'closed']), $csrf)['status'] === 409, 'An order cannot be resolved twice.');
    $status = acRequest($base, $folder . '/kiosk.cookie', 'demo-order', null, null, ['X-Demo-Order-Key: ' . $kioskKey]);
    acExpect($status['body']['resolution']['action'] === 'refunded' && $status['body']['resolution']['refunded_centavos'] === 3000
        && $status['body']['order']['order_status'] === 'failed' && $status['body']['order']['payment_status'] === 'refunded', 'Kiosk now sees the staff resolution and can finish the session.');
    $stockBefore = (int) $database->query('SELECT stock_quantity FROM products WHERE id = ' . $productId)->fetchColumn();
    acExpect($stockBefore === 5, 'A hand refund does not change stock.');

    [, $handRef] = $stuck(2, 2000, 1);
    $hand = acRequest($base, $desk = $folder . '/desk3.cookie', 'staff-reviews', ['kind' => 'order', 'event_id' => bin2hex(random_bytes(32)), 'reference' => $handRef, 'action' => 'handed_over', 'note' => 'Gave the remaining tablet by hand'], acRequest($base, $folder . '/desk3.cookie', 'staff-session')['body']['csrf_token']);
    acExpect($hand['status'] === 200, 'Staff role can resolve orders: ' . json_encode($hand));
    $handOrder = medivendReadOrder($database, $handRef);
    acExpect($handOrder['order_status'] === 'completed' && $handOrder['items'][0]['dispensed_quantity'] === 2
        && (int) $database->query('SELECT stock_quantity FROM products WHERE id = ' . $productId)->fetchColumn() === 4, 'Hand-over completes the order and reduces stock by the one remaining unit.');
    $resolveAs = static fn(string $ref, string $action) => acRequest($base, $tabletB, 'staff-reviews', ['kind' => 'order', 'event_id' => bin2hex(random_bytes(32)), 'reference' => $ref, 'action' => $action, 'note' => 'Test resolution note'], $csrf);
    [, $lateRef] = $stuck(1, 4600, 1); // delivered, then ₱36 of late coins (the kiosk screenshot case)
    acExpect($resolveAs($lateRef, 'handed_over')['status'] === 409, 'Nothing to hand over when every unit was delivered.');
    acExpect($resolveAs($lateRef, 'refunded')['status'] === 200, 'Excess after delivery is returned by hand.');
    $late = $database->prepare('SELECT r.refunded_centavos, t.order_status, t.payment_status FROM order_resolutions r JOIN transactions t ON t.id = r.transaction_id WHERE t.reference = ?');
    $late->execute([$lateRef]); $lateRow = $late->fetch();
    acExpect((int) $lateRow['refunded_centavos'] === 3600 && $lateRow['order_status'] === 'completed' && $lateRow['payment_status'] === 'paid', 'Only the ₱36 excess is recorded as returned; the delivered order stays completed: ' . json_encode($lateRow));
    [, $halfRef] = $stuck(2, 2000, 1); // one of two units delivered, then a jam
    acExpect($resolveAs($halfRef, 'refunded')['status'] === 200, 'Partial delivery can be refunded.');
    $late->execute([$halfRef]); $halfRow = $late->fetch();
    acExpect((int) $halfRow['refunded_centavos'] === 1000 && $halfRow['payment_status'] === 'refunded', 'Refund covers only the undelivered unit: ' . json_encode($halfRow));
    [, $partialRef] = $stuck(2, 1000, 0);
    acExpect(acRequest($base, $tabletB, 'staff-reviews', ['kind' => 'order', 'event_id' => bin2hex(random_bytes(32)), 'reference' => $partialRef, 'action' => 'handed_over', 'note' => 'Should be refused'], $csrf)['status'] === 409, 'Hand-over requires full payment.');
    $coinId = bin2hex(random_bytes(32));
    $database->prepare("INSERT INTO hardware_unassigned_coins (event_id, coin_centavos, reason) VALUES (?, 500, 'no_active_order')")->execute([$coinId]);
    acExpect(acRequest($base, $tabletB, 'staff-reviews', ['kind' => 'coin', 'event_id' => $coinId, 'action' => 'refunded', 'note' => 'Returned coin to student'], $csrf)['status'] === 200
        && $database->query("SELECT resolution FROM hardware_unassigned_coins WHERE event_id = '$coinId'")->fetchColumn() === 'refunded', 'Stray coins can be resolved.');
    $history = acRequest($base, $tabletB, 'staff-reviews')['body'];
    acExpect(count($history['recent']) === 4 && $history['recent'][0]['staff_user'] !== '' && count($history['unassigned_coins']) === 0, 'Resolutions are listed with who recorded them.');
    echo "PASS: refunds, hand-overs (stock-checked), duplicate/replay safety, stray coins, and the kiosk sees each resolution.\n";
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    if (is_file($folder . '/server.log')) fwrite(STDERR, implode("\n", array_slice(file($folder . '/server.log', FILE_IGNORE_NEW_LINES) ?: [], -15)) . "\n");
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    putenv($originalName === false ? 'MEDIVEND_DB_NAME' : 'MEDIVEND_DB_NAME=' . $originalName);
    if ($created && $admin) $admin->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    if (is_dir($folder)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($folder);
    }
    echo $failed ? '' : "Temporary database and server removed; medivend_db was not touched.\n";
}
exit($failed ? 1 : 0);
