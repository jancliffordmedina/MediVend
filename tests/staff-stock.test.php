<?php
declare(strict_types=1);
// CLI-only tests. All writes use a new random database and test credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/database.php';

function staffExpect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function staffCurl(string $base, string $cookie, string $route, ?array $body = null, ?string $csrf = null)
{
    $curl = curl_init($base . '/api/' . $route . '.php');
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($csrf !== null) $headers[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 2]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    return $curl;
}

function staffResponse($curl, string $wire): array
{
    $length = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'headers' => substr($wire, 0, $length),
        'body' => json_decode(substr($wire, $length), true, 512, JSON_THROW_ON_ERROR)];
}

function staffRequest(string $base, string $cookie, string $route, ?array $body = null, ?string $csrf = null): array
{
    $curl = staffCurl($base, $cookie, $route, $body, $csrf);
    try {
        $wire = curl_exec($curl);
        staffExpect(is_string($wire), 'HTTP request failed: ' . curl_error($curl));
        return staffResponse($curl, $wire);
    } finally { curl_close($curl); }
}

function staffConcurrent(array $requests): array
{
    $multi = curl_multi_init(); $handles = []; $results = [];
    foreach ($requests as $request) { $curl = staffCurl(...$request); $handles[] = $curl; curl_multi_add_handle($multi, $curl); }
    try {
        do { $state = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, .1); } while ($running && $state === CURLM_OK);
        foreach ($handles as $curl) {
            $wire = curl_multi_getcontent($curl);
            staffExpect(is_string($wire), 'Concurrent response missing.'); $results[] = staffResponse($curl, $wire);
        }
    } finally { foreach ($handles as $curl) { curl_multi_remove_handle($multi, $curl); curl_close($curl); } curl_multi_close($multi); }
    return $results;
}

function staffServer(array $environment, string $folder, array &$servers): string
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    staffExpect(is_resource($listener), 'Cannot allocate isolated port: ' . $error);
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $log = $folder . '/server-' . count($servers) . '.log';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $folder . '/sessions', '-S', $address, '-t', realpath(__DIR__ . '/..')],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
    staffExpect(is_resource($server), 'Cannot launch staff HTTP server.'); fclose($pipes[0]); $servers[] = $server;
    $base = 'http://' . $address;
    for ($try = 0; $try < 60; $try++) {
        $curl = curl_init($base . '/api/staff-session.php'); curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($status) return $base; usleep(50000);
    }
    throw new RuntimeException('Staff server did not start: ' . $log);
}

function staffLoginForTest(string $base, string $cookie, string $password): array
{
    $state = staffRequest($base, $cookie, 'staff-session');
    staffExpect($state['status'] === 200 && isset($state['body']['csrf_token']), 'Session bootstrap must work.');
    $login = staffRequest($base, $cookie, 'staff-session', ['action' => 'login', 'username' => 'stock-tester', 'password' => $password], $state['body']['csrf_token']);
    staffExpect($login['status'] === 200 && $login['body']['authenticated'], 'Configured staff must be able to log in: ' . json_encode($login));
    return $login['body'];
}

function staffCookieId(string $file): string
{
    $contents = (string) file_get_contents($file);
    staffExpect(preg_match('/\tmedivend_staff\t([^\r\n]+)/', $contents, $match) === 1, 'Staff session cookie is missing.');
    return $match[1];
}

function staffSetupChild(array $arguments, string $input): array
{
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../scripts/setup-staff.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    staffExpect(is_resource($process), 'Cannot run credential setup test.');
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $out . $errors];
}

$name = 'medivend_stafftest_' . bin2hex(random_bytes(6));
$folder = sys_get_temp_dir() . '/medivend-stafftest-' . bin2hex(random_bytes(6));
$originalName = getenv('MEDIVEND_DB_NAME'); $admin = null; $database = null; $created = false; $failed = false; $servers = [];
try {
    staffExpect(extension_loaded('curl'), 'PHP curl is required.');
    staffExpect((bool) preg_match('/\Amedivend_stafftest_[a-f0-9]{12}\z/', $name), 'Invalid isolated database name.');
    mkdir($folder, 0700); mkdir($folder . '/sessions', 0700);
    $privateFile = $folder . '/private.env';
    $existingEnvironment = "# Existing deployment settings\nMEDIVEND_MODE=simulation\nMEDIVEND_DB_NAME=preserved_db\nMEDIVEND_DB_PASSWORD=\"preserved-secret\"\n";
    file_put_contents($privateFile, $existingEnvironment); chmod($privateFile, 0640);
    $setupPassword = bin2hex(random_bytes(18));
    $setupArguments = ['--username', 'stock-tester', '--env-file', $privateFile, '--password-stdin'];
    $setup = staffSetupChild($setupArguments, $setupPassword . "\n" . $setupPassword . "\n");
    staffExpect($setup['exit'] === 0, 'Private credential setup failed: ' . $setup['output']);
    $configured = parse_ini_file($privateFile, false, INI_SCANNER_RAW);
    staffExpect($configured['MEDIVEND_MODE'] === 'simulation' && $configured['MEDIVEND_DB_NAME'] === 'preserved_db' && $configured['MEDIVEND_DB_PASSWORD'] === 'preserved-secret', 'Credential setup changed existing deployment settings.');
    staffExpect($configured['MEDIVEND_STAFF_USERNAME'] === 'stock-tester' && password_verify($setupPassword, $configured['MEDIVEND_STAFF_PASSWORD_HASH']), 'Credential setup must save a verifiable password hash.');
    staffExpect(strpos((string) file_get_contents($privateFile), $setupPassword) === false && strpos($setup['output'], $setupPassword) === false, 'Credential setup exposed the password.');
    $configuredBytes = file_get_contents($privateFile);
    foreach (["short\nshort\n", $setupPassword . "\nmismatch\n"] as $invalidInput) {
        $invalidSetup = staffSetupChild($setupArguments, $invalidInput);
        staffExpect($invalidSetup['exit'] !== 0 && file_get_contents($privateFile) === $configuredBytes, 'Invalid password changed private configuration.');
    }
    $webPath = __DIR__ . '/staff-setup-should-not-exist.env';
    staffExpect(!file_exists($webPath), 'Unsafe setup test path unexpectedly exists.');
    $unsafe = staffSetupChild(['--username', 'stock-tester', '--env-file', $webPath, '--password-stdin'], $setupPassword . "\n" . $setupPassword . "\n");
    staffExpect($unsafe['exit'] !== 0 && !file_exists($webPath), 'Credential setup accepted a file inside the web project.');
    if (PHP_OS_FAMILY !== 'Windows') { clearstatcache(true, $privateFile); staffExpect((fileperms($privateFile) & 0777) === 0640, 'Credential setup changed the private file mode.'); }
    echo "PASS: private credential setup preserves deployment settings, hashes secrets, and rejects invalid passwords/web paths.\n";
    $admin = medivendDatabase();
    $admin->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $password = bin2hex(random_bytes(18));
    $environment = array_merge(getenv(), ['MEDIVEND_DB_NAME' => $name, 'MEDIVEND_MODE' => 'simulation',
        'MEDIVEND_STAFF_USERNAME' => 'stock-tester', 'MEDIVEND_STAFF_PASSWORD_HASH' => password_hash($password, PASSWORD_DEFAULT),
        'MEDIVEND_STAFF_RATE_DIR' => $folder . '/rate']);
    $child = proc_open([PHP_BINARY, __DIR__ . '/../scripts/migrate.php'], [0 => ['pipe', 'r'], 1 => ['file', $folder . '/migration.log', 'a'], 2 => ['file', $folder . '/migration.log', 'a']], $pipes, null, $environment);
    staffExpect(is_resource($child), 'Cannot run isolated migration.'); fclose($pipes[0]);
    staffExpect(proc_close($child) === 0, 'Migration failed; inspect ' . $folder . '/migration.log');
    putenv('MEDIVEND_DB_NAME=' . $name); $database = medivendDatabase();
    $insert = $database->prepare('INSERT INTO products (sku,name,price_centavos,stock_quantity,compartment_number,is_active,is_demo) VALUES (?,?,1000,?,?,?,?)');
    foreach ([['STOCK-A', 'Demo medicine', 5, 1, 1, 1], ['STOCK-B', 'Second medicine', 8, 2, 1, 1],
        ['STOCK-INACTIVE', 'Inactive medicine', 4, 3, 0, 1], ['STOCK-REAL', 'Physical medicine', 11, 4, 1, 0]] as $values) $insert->execute($values);
    [$a, $b, $inactive, $physical] = array_map('intval', $database->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $stock = static function (int $id) use ($database): int { $q = $database->prepare('SELECT stock_quantity FROM products WHERE id=?'); $q->execute([$id]); return (int) $q->fetchColumn(); };
    $base = staffServer($environment, $folder, $servers); $cookie = $folder . '/staff.cookie';
    $anonymous = staffRequest($base, $cookie, 'staff-session'); $anonymousToken = $anonymous['body']['csrf_token'];
    $anonymousId = staffCookieId($cookie);
    staffExpect($anonymous['status'] === 200 && !$anonymous['body']['authenticated'] && $anonymous['body']['configured'], 'Anonymous bootstrap must not authenticate.');
    staffExpect(stripos($anonymous['headers'], 'HttpOnly') !== false && stripos($anonymous['headers'], 'SameSite=Strict') !== false && stripos($anonymous['headers'], 'no-store') !== false, 'Staff cookies and responses need private session settings.');
    staffExpect(staffRequest($base, $cookie, 'staff-stock')['status'] === 401, 'Anonymous catalog access must fail.');
    $intent = ['event_id' => bin2hex(random_bytes(32)), 'product_id' => $a, 'quantity' => 3, 'note' => 'Counted restock test'];
    staffExpect(staffRequest($base, $cookie, 'staff-stock', $intent, $anonymousToken)['status'] === 401, 'Public CSRF token must not authorize restocking.');
    staffExpect(staffRequest($base, $cookie, 'staff-session', ['action' => 'login', 'username' => 'stock-tester', 'password' => $password])['status'] === 403, 'Login needs CSRF protection.');
    staffExpect(staffRequest($base, $cookie, 'staff-session', ['action' => 'login', 'username' => 'stock-tester', 'password' => 'incorrect password'], $anonymousToken)['status'] === 401, 'Wrong password must fail.');
    $session = staffLoginForTest($base, $cookie, $password); $csrf = $session['csrf_token'];
    staffExpect($csrf !== $anonymousToken && $session['username'] === 'stock-tester', 'Successful login must rotate token and identify actor.');
    staffExpect(staffCookieId($cookie) !== $anonymousId, 'Successful login must regenerate its session ID.');
    $view = staffRequest($base, $cookie, 'staff-stock');
    staffExpect($view['status'] === 200 && $view['body']['mode'] === 'simulation' && count($view['body']['products']) === 3, 'Simulation view must contain active and inactive demo products only.');
    staffExpect(!in_array($physical, array_map('intval', array_column($view['body']['products'], 'id')), true), 'Physical inventory must not appear in simulation catalog.');
    staffExpect(staffRequest($base, $cookie, 'staff-stock', $intent, str_repeat('f', 64))['status'] === 403, 'Forged CSRF must not mutate stock.');
    echo "PASS: session bootstrap, authentication, CSRF, private cookies, and mode-filtered stock.\n";

    foreach ([0, -1, 1.2, '3', true, null, 10001] as $quantity) {
        $bad = array_replace($intent, ['event_id' => bin2hex(random_bytes(32)), 'quantity' => $quantity]);
        staffExpect(staffRequest($base, $cookie, 'staff-stock', $bad, $csrf)['status'] === 422, 'Invalid integer quantity accepted: ' . json_encode($quantity));
    }
    foreach ([['product_id' => '1'], ['product_id' => 0], ['product_id' => 999999], ['event_id' => 'invalid'], ['note' => ''], ['note' => str_repeat('x', 241)],
        ['staff_user' => 'administrator'], ['stock_after' => 999], ['is_demo' => false], ['price_centavos' => 1], ['mode' => 'hardware']] as $override) {
        $bad = array_replace($intent, ['event_id' => bin2hex(random_bytes(32))], $override);
        staffExpect(staffRequest($base, $cookie, 'staff-stock', $bad, $csrf)['status'] >= 400, 'Invalid or forged fields accepted: ' . implode(',', array_keys($override)));
    }
    foreach ([$inactive, $physical] as $id) staffExpect(staffRequest($base, $cookie, 'staff-stock', array_replace($intent, ['event_id' => bin2hex(random_bytes(32)), 'product_id' => $id]), $csrf)['status'] >= 400, 'Inactive or wrong-mode stock mutation accepted.');
    staffExpect($stock($a) === 5 && $stock($b) === 8 && $stock($inactive) === 4 && $stock($physical) === 11, 'Rejected requests changed stock.');
    $added = staffRequest($base, $cookie, 'staff-stock', $intent, $csrf);
    staffExpect(in_array($added['status'], [200, 201], true) && !$added['body']['replayed'] && $stock($a) === 8, 'Valid restock must add three units.');
    $receipt = $added['body']['restock'];
    staffExpect((int) $receipt['stock_before'] === 5 && (int) $receipt['stock_after'] === 8 && (int) $receipt['quantity'] === 3 && $receipt['staff_user'] === 'stock-tester', 'Audit receipt is incorrect.');
    $retry = staffRequest($base, $cookie, 'staff-stock', $intent, $csrf);
    staffExpect($retry['status'] === 200 && $retry['body']['replayed'] && $retry['body']['restock'] === $receipt && $stock($a) === 8, 'Same-intent retry must return original receipt without adding twice.');
    foreach ([['quantity' => 4], ['product_id' => $b], ['note' => 'Different delivery']] as $override) staffExpect(staffRequest($base, $cookie, 'staff-stock', array_replace($intent, $override), $csrf)['status'] === 409, 'Conflicting idempotency key must fail.');
    echo "PASS: validation, forbidden fields, audit before/after values, and duplicate/conflicting retries.\n";

    // Separate HTTP servers and separate cookies prevent PHP server/session
    // serialization from making these merely sequential database tests.
    $bases = [$base]; $cookies = [$cookie]; $tokens = [$csrf];
    for ($i = 1; $i < 3; $i++) { $bases[] = staffServer($environment, $folder, $servers); $cookies[] = $folder . '/parallel-' . $i . '.cookie'; $tokens[] = staffLoginForTest($bases[$i], $cookies[$i], $password)['csrf_token']; }
    $same = array_replace($intent, ['event_id' => bin2hex(random_bytes(32)), 'quantity' => 7, 'note' => 'Concurrent same delivery']);
    $requests = []; for ($i = 0; $i < 3; $i++) $requests[] = [$bases[$i], $cookies[$i], 'staff-stock', $same, $tokens[$i]];
    $results = staffConcurrent($requests);
    staffExpect(count(array_filter($results, static fn(array $r): bool => in_array($r['status'], [200, 201], true))) === 3, 'Concurrent same-event requests must all resolve successfully.');
    staffExpect(count(array_filter($results, static fn(array $r): bool => !$r['body']['replayed'])) === 1 && $stock($a) === 15, 'Concurrent retry credited more than once.');
    $requests = []; for ($i = 0; $i < 3; $i++) $requests[] = [$bases[$i], $cookies[$i], 'staff-stock', array_replace($intent, ['event_id' => bin2hex(random_bytes(32)), 'quantity' => $i + 1, 'note' => 'Distinct delivery ' . $i]), $tokens[$i]];
    $results = staffConcurrent($requests);
    staffExpect(count(array_filter($results, static fn(array $r): bool => in_array($r['status'], [200, 201], true))) === 3 && $stock($a) === 21, 'Distinct concurrent increments were lost.');
    staffExpect((int) $database->query('SELECT COUNT(*) FROM staff_stock_events')->fetchColumn() === 5, 'Exactly five successful deliveries must exist in the audit ledger.');
    echo "PASS: concurrent duplicate delivery commits once; distinct deliveries do not lose updates.\n";

    // Force a ledger-storage failure; the product increase must roll back too.
    $database->exec("CREATE TRIGGER staff_test_reject BEFORE INSERT ON staff_stock_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated ledger failure'");
    $failure = staffRequest($base, $cookie, 'staff-stock', array_replace($intent, ['event_id' => bin2hex(random_bytes(32)), 'product_id' => $b]), $csrf);
    staffExpect($failure['status'] === 503 && $stock($b) === 8, 'Failed audit insert must roll back stock.');
    $database->exec('DROP TRIGGER staff_test_reject');
    staffExpect((int) $database->query('SELECT COUNT(*) FROM staff_stock_events')->fetchColumn() === 5, 'Failed ledger transaction left a partial audit row.');
    echo "PASS: audit storage failure rolls back inventory and ledger together.\n";

    $hardware = staffServer(array_replace($environment, ['MEDIVEND_MODE' => 'hardware']), $folder, $servers); $hc = $folder . '/hardware.cookie';
    $ht = staffLoginForTest($hardware, $hc, $password)['csrf_token']; $view = staffRequest($hardware, $hc, 'staff-stock');
    staffExpect($view['status'] === 200 && count($view['body']['products']) === 1 && (int) $view['body']['products'][0]['id'] === $physical, 'Hardware stock view must contain physical inventory only.');
    staffExpect(staffRequest($hardware, $hc, 'staff-stock', array_replace($intent, ['event_id' => bin2hex(random_bytes(32))]), $ht)['status'] >= 400, 'Hardware mode accepted a demo stock mutation.');
    $logout = staffRequest($base, $cookie, 'staff-session', ['action' => 'logout'], $csrf);
    staffExpect($logout['status'] === 200 && !$logout['body']['authenticated'] && staffRequest($base, $cookie, 'staff-stock')['status'] === 401, 'Logout must revoke stock access.');
    $unset = staffServer(array_replace($environment, ['MEDIVEND_STAFF_USERNAME' => '', 'MEDIVEND_STAFF_PASSWORD_HASH' => '']), $folder, $servers); $uc = $folder . '/unset.cookie'; $state = staffRequest($unset, $uc, 'staff-session');
    staffExpect(!$state['body']['configured'] && !$state['body']['authenticated'], 'Unconfigured access must be disabled.');
    staffExpect(staffRequest($unset, $uc, 'staff-session', ['action' => 'login', 'username' => 'admin', 'password' => 'admin'], $state['body']['csrf_token'])['status'] === 503, 'Default credentials must not enable staff access.');
    staffExpect($stock($b) === 8 && $stock($inactive) === 4 && $stock($physical) === 11, 'Unrelated stock changed.');
    echo "PASS: demo/physical isolation, logout, and disabled-by-default credentials.\n";

    // Age only this test-owned session through PHP's session API. This avoids
    // a 15 minute wait while verifying the real endpoint expiry behavior.
    $expiredCookie = $folder . '/expired.cookie';
    staffLoginForTest($base, $expiredCookie, $password); $expiredId = staffCookieId($expiredCookie);
    $expireScript = 'session_name("medivend_staff"); session_id($argv[1]); session_start(); $_SESSION["staff_seen"]=time()-901; session_write_close();';
    $expiry = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $folder . '/sessions', '-r', $expireScript, $expiredId],
        [0 => ['pipe', 'r'], 1 => ['file', $folder . '/expiry.log', 'a'], 2 => ['file', $folder . '/expiry.log', 'a']], $pipes, null, $environment);
    staffExpect(is_resource($expiry), 'Cannot age isolated session.'); fclose($pipes[0]); staffExpect(proc_close($expiry) === 0, 'Cannot save aged test session.');
    $expired = staffRequest($base, $expiredCookie, 'staff-session');
    staffExpect($expired['status'] === 200 && !$expired['body']['authenticated'] && staffRequest($base, $expiredCookie, 'staff-stock')['status'] === 401, 'Idle expiry must revoke stock access.');
    echo "PASS: sessions expire after 15 minutes of inactivity.\n";

    // Seven more failures reach eight total. A new cookie must not bypass the
    // account-wide attempt budget, including when the next password is correct.
    for ($i = 0; $i < 7; $i++) { $attemptCookie = $folder . '/wrong-' . $i . '.cookie'; $state = staffRequest($base, $attemptCookie, 'staff-session'); staffExpect(staffRequest($base, $attemptCookie, 'staff-session', ['action' => 'login', 'username' => 'stock-tester', 'password' => 'wrong'], $state['body']['csrf_token'])['status'] === 401, 'Failed-attempt budget should allow eight failures before locking.'); }
    $rateCookie = $folder . '/rate.cookie'; $state = staffRequest($base, $rateCookie, 'staff-session');
    $rate = staffRequest($base, $rateCookie, 'staff-session', ['action' => 'login', 'username' => 'stock-tester', 'password' => $password], $state['body']['csrf_token']);
    staffExpect($rate['status'] === 429 && stripos($rate['headers'], 'Retry-After:') !== false, 'New cookies must not bypass persistent throttling.');
    echo "PASS: login throttling persists across independent browser sessions.\n";
} catch (Throwable $error) { $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL); }
finally {
    foreach ($servers as $server) if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($database instanceof PDO && $database->inTransaction()) $database->rollBack();
    if ($created && $admin instanceof PDO && preg_match('/\Amedivend_stafftest_[a-f0-9]{12}\z/', $name)) $admin->exec('DROP DATABASE `' . $name . '`');
    putenv($originalName === false ? 'MEDIVEND_DB_NAME' : 'MEDIVEND_DB_NAME=' . $originalName);
    if ($failed) fwrite(STDERR, 'Test-only logs retained: ' . $folder . PHP_EOL);
    elseif (is_dir($folder)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($folder); echo "Temporary database and test servers removed; existing database records were not modified.\n";
    }
}
exit($failed ? 1 : 0);
