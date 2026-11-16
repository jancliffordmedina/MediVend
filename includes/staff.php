<?php
declare(strict_types=1);
require_once __DIR__ . '/machine.php';

function medivendStaffConfig(): array
{
    return require __DIR__ . '/../config/staff.php';
}

function medivendStaffConfigured(array $config): bool
{
    $info = password_get_info($config['password_hash']);
    return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}\z/', $config['username']) === 1
        && ($info['algoName'] ?? 'unknown') !== 'unknown';
}

function medivendStaffHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function medivendStaffStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('medivend_staff');
    $path = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/staff-session.php')));
    $path = rtrim($path, '/.') . '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (!session_start()) throw new RuntimeException('Unable to start staff session.');
    $config = medivendStaffConfig();
    $fingerprint = hash('sha256', $config['username'] . "\0" . $config['password_hash']);
    if (isset($_SESSION['staff_user']) && (
        !medivendStaffConfigured($config)
        || ($_SESSION['staff_fingerprint'] ?? '') !== $fingerprint
        || (int) ($_SESSION['staff_seen'] ?? 0) < time() - $config['idle_timeout_seconds']
    )) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!isset($_SESSION['staff_csrf'])) $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}

function medivendStaffView(): array
{
    $config = medivendStaffConfig();
    return [
        'configured' => medivendStaffConfigured($config),
        'authenticated' => isset($_SESSION['staff_user']),
        'csrf_token' => $_SESSION['staff_csrf'],
        'username' => $_SESSION['staff_user'] ?? null,
        'idle_timeout_seconds' => $config['idle_timeout_seconds'],
    ];
}

function medivendRequireStaff(): string
{
    if (!isset($_SESSION['staff_user'])) {
        throw new MedivendCheckoutError(401, 'staff_login_required', 'Sign in to manage stock.');
    }
    $_SESSION['staff_seen'] = time();
    return $_SESSION['staff_user'];
}

function medivendStaffCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['staff_csrf'], $token)) {
        throw new MedivendCheckoutError(403, 'csrf_failed', 'Your session changed. Refresh the page and try again.');
    }
}

function medivendStaffPayload(): stdClass
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new MedivendCheckoutError(415, 'json_required', 'Send the request as JSON.');
    }
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if ($body === false || strlen($body) > 8192) {
        throw new MedivendCheckoutError(413, 'request_too_large', 'The request is too large.');
    }
    try { $payload = json_decode($body, false, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new MedivendCheckoutError(400, 'invalid_json', 'Send a valid JSON object.'); }
    if (!$payload instanceof stdClass) throw new MedivendCheckoutError(422, 'invalid_request', 'Send a JSON object.');
    return $payload;
}

function medivendStaffKeys(stdClass $payload, array $expected): bool
{
    $actual = array_keys(get_object_vars($payload));
    sort($actual); sort($expected);
    return $actual === $expected;
}

/** Account-wide durable throttling prevents resets by opening a new session. */
function medivendStaffLogin(string $username, string $password): void
{
    $config = medivendStaffConfig();
    if (!medivendStaffConfigured($config)) {
        throw new MedivendCheckoutError(503, 'staff_unconfigured', 'Staff access is not configured. Ask the administrator to run the staff setup command.');
    }
    $directory = $config['rate_directory'];
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to create rate storage.');
    $resolved = str_replace('\\', '/', (string) realpath($directory));
    if (!$resolved || $resolved === $root || strpos($resolved, $root . '/') === 0) throw new RuntimeException('Rate storage must be private.');
    $file = $directory . '/login-' . hash('sha256', $root . "\0" . $config['username']) . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock rate storage.');
    try {
        $raw = stream_get_contents($handle);
        $record = $raw ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : [];
        $now = time();
        $attempts = array_values(array_filter($record['attempts'] ?? [], static fn($t): bool => is_int($t) && $t > $now - 900));
        if (count($attempts) >= 8) {
            $wait = max(1, $attempts[0] + 900 - $now);
            header('Retry-After: ' . $wait);
            throw new MedivendCheckoutError(429, 'staff_rate_limited', 'Too many sign-in attempts. Wait 15 minutes before trying again.');
        }
        $validPassword = password_verify($password, $config['password_hash']);
        $valid = hash_equals($config['username'], $username) && $validPassword;
        if (!$valid) $attempts[] = $now;
        // Do not erase failed attempts on successful login; opening a new
        // cookie or using a known password cannot reset the account's budget.
        if (!ftruncate($handle, 0) || !rewind($handle)) throw new RuntimeException('Unable to update rate storage.');
        $encoded = json_encode(['attempts' => $attempts], JSON_THROW_ON_ERROR);
        if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new RuntimeException('Unable to save rate storage.');
        if (!$valid) throw new MedivendCheckoutError(401, 'staff_login_failed', 'Username or password is incorrect.');
    } finally {
        flock($handle, LOCK_UN); fclose($handle);
    }
    session_regenerate_id(true);
    $_SESSION = [
        'staff_user' => $config['username'],
        'staff_seen' => time(),
        'staff_fingerprint' => hash('sha256', $config['username'] . "\0" . $config['password_hash']),
        'staff_csrf' => bin2hex(random_bytes(32)),
    ];
}

function medivendStaffLogout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}

function medivendStaffError(Throwable $error): void
{
    if ($error instanceof MedivendCheckoutError) {
        http_response_code($error->status);
        $message = $error->getMessage(); $code = $error->errorCode;
    } else {
        error_log('[MediVend staff] ' . $error->getMessage());
        http_response_code(503);
        $message = 'Staff service is temporarily unavailable. Keep any pending restock and retry the same request.';
        $code = 'staff_unavailable';
    }
    echo json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
