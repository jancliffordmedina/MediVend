<?php
declare(strict_types=1);
require_once __DIR__ . '/machine.php';

/*
 * Staff authentication: one sign-in page for every clinic user.
 *  - Accounts live in staff_users (roles: admin, staff).
 *  - One active sign-in per user: signing in elsewhere ends the earlier session.
 *  - The legacy private-environment account (MEDIVEND_STAFF_USERNAME/_HASH)
 *    works only as a bootstrap admin while no active admin account exists, so
 *    an existing installation can sign in once and create real accounts.
 */

const MEDIVEND_USERNAME_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}\z/';

function medivendStaffConfig(): array
{
    return require __DIR__ . '/../config/staff.php';
}

/** Legacy private-environment account is well formed. */
function medivendStaffConfigured(array $config): bool
{
    $info = password_get_info($config['password_hash']);
    return preg_match(MEDIVEND_USERNAME_PATTERN, $config['username']) === 1
        && ($info['algoName'] ?? 'unknown') !== 'unknown';
}

function medivendActiveAdminCount(PDO $database): int
{
    return (int) $database->query("SELECT COUNT(*) FROM staff_users WHERE is_active = 1 AND role = 'admin'")->fetchColumn();
}

function medivendBootstrapAllowed(PDO $database, array $config): bool
{
    return medivendStaffConfigured($config) && medivendActiveAdminCount($database) === 0;
}

function medivendStaffHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function medivendStaffClear(?string $notice = null): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
    if ($notice !== null) $_SESSION['staff_notice'] = $notice;
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
    if (isset($_SESSION['staff_user'])) {
        if ((int) ($_SESSION['staff_seen'] ?? 0) < time() - $config['idle_timeout_seconds']) {
            medivendStaffClear('expired');
        } elseif (isset($_SESSION['staff_user_id'])) {
            $query = medivendDatabase()->prepare('SELECT username, display_name, role, is_active, session_token_hash FROM staff_users WHERE id = ?');
            $query->execute([$_SESSION['staff_user_id']]); $user = $query->fetch();
            if (!$user || !(int) $user['is_active']) medivendStaffClear('account_disabled');
            elseif (!is_string($user['session_token_hash']) || !hash_equals($user['session_token_hash'], hash('sha256', (string) ($_SESSION['staff_token'] ?? '')))) {
                medivendStaffClear('signed_in_elsewhere');
            } else {
                $_SESSION['staff_user'] = $user['username'];
                $_SESSION['staff_name'] = $user['display_name'];
                $_SESSION['staff_role'] = $user['role'];
            }
        } else {
            $fingerprint = hash('sha256', $config['username'] . "\0" . $config['password_hash']);
            if (($_SESSION['staff_fingerprint'] ?? '') !== $fingerprint || !medivendBootstrapAllowed(medivendDatabase(), $config)) {
                medivendStaffClear('bootstrap_ended');
            }
        }
    }
    if (!isset($_SESSION['staff_csrf'])) $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}

function medivendStaffView(): array
{
    $config = medivendStaffConfig();
    $database = medivendDatabase();
    $accounts = (int) $database->query('SELECT COUNT(*) FROM staff_users WHERE is_active = 1')->fetchColumn();
    $notice = $_SESSION['staff_notice'] ?? null;
    unset($_SESSION['staff_notice']);
    return [
        'configured' => $accounts > 0 || medivendBootstrapAllowed($database, $config),
        'authenticated' => isset($_SESSION['staff_user']),
        'csrf_token' => $_SESSION['staff_csrf'],
        'username' => $_SESSION['staff_user'] ?? null,
        'display_name' => $_SESSION['staff_name'] ?? null,
        'role' => $_SESSION['staff_role'] ?? null,
        'bootstrap' => isset($_SESSION['staff_user']) && !isset($_SESSION['staff_user_id']),
        'notice' => $notice,
        'machine_mode' => medivendMachineConfig()['mode'],
        'idle_timeout_seconds' => $config['idle_timeout_seconds'],
    ];
}

function medivendRequireStaff(): string
{
    if (!isset($_SESSION['staff_user'])) {
        throw new MedivendCheckoutError(401, 'staff_login_required', 'Sign in to continue.');
    }
    $_SESSION['staff_seen'] = time();
    return $_SESSION['staff_user'];
}

function medivendRequireAdmin(): string
{
    $actor = medivendRequireStaff();
    if (($_SESSION['staff_role'] ?? '') !== 'admin') throw new MedivendCheckoutError(403, 'admin_required', 'Only an administrator can manage user accounts.');
    return $actor;
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

/** Account-wide durable throttling (per attempted username) survives new cookies. */
function medivendStaffThrottle(string $username, callable $verify): bool
{
    $config = medivendStaffConfig();
    $directory = $config['rate_directory'];
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to create rate storage.');
    $resolved = str_replace('\\', '/', (string) realpath($directory));
    if (!$resolved || $resolved === $root || strpos($resolved, $root . '/') === 0) throw new RuntimeException('Rate storage must be private.');
    $file = $directory . '/login-' . hash('sha256', $root . "\0" . strtolower($username)) . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock rate storage.');
    try {
        $raw = stream_get_contents($handle);
        $record = $raw ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : [];
        $now = time();
        $attempts = array_values(array_filter($record['attempts'] ?? [], static fn($t): bool => is_int($t) && $t > $now - 900));
        if (count($attempts) >= 8) {
            header('Retry-After: ' . max(1, $attempts[0] + 900 - $now));
            throw new MedivendCheckoutError(429, 'staff_rate_limited', 'Too many sign-in attempts. Wait 15 minutes before trying again.');
        }
        $valid = (bool) $verify();
        if (!$valid) $attempts[] = $now;
        // Successful sign-in does not erase failures: a known password cannot reset the budget.
        if (!ftruncate($handle, 0) || !rewind($handle)) throw new RuntimeException('Unable to update rate storage.');
        $encoded = json_encode(['attempts' => $attempts], JSON_THROW_ON_ERROR);
        if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new RuntimeException('Unable to save rate storage.');
        return $valid;
    } finally {
        flock($handle, LOCK_UN); fclose($handle);
    }
}

function medivendStaffLogin(string $username, string $password): void
{
    $config = medivendStaffConfig();
    $database = medivendDatabase();
    $bootstrap = medivendBootstrapAllowed($database, $config);
    $activeAccounts = (int) $database->query('SELECT COUNT(*) FROM staff_users WHERE is_active = 1')->fetchColumn();
    if ($activeAccounts === 0 && !$bootstrap) {
        throw new MedivendCheckoutError(503, 'staff_unconfigured', 'No staff accounts exist yet. Ask the administrator to run scripts/create-user.php.');
    }
    $query = $database->prepare('SELECT id, username, display_name, role, password_hash, is_active FROM staff_users WHERE username = ?');
    $query->execute([$username]); $user = $query->fetch() ?: null;
    $kind = null;
    $valid = medivendStaffThrottle($username, static function () use ($user, $username, $password, $bootstrap, $config, &$kind): bool {
        if ($user && (int) $user['is_active']) { $kind = 'account'; return password_verify($password, $user['password_hash']); }
        if (!$user && $bootstrap && hash_equals($config['username'], $username)) { $kind = 'bootstrap'; return password_verify($password, $config['password_hash']); }
        password_verify($password, password_hash('medivend-unknown-user', PASSWORD_DEFAULT)); // similar timing for unknown users
        return false;
    });
    if (!$valid) throw new MedivendCheckoutError(401, 'staff_login_failed', 'Username or password is incorrect.');
    session_regenerate_id(true);
    if ($kind === 'account') {
        $token = bin2hex(random_bytes(32));
        $update = $database->prepare('UPDATE staff_users SET session_token_hash = ?, last_login_at = CURRENT_TIMESTAMP' . (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT) ? ', password_hash = ?' : '') . ' WHERE id = ?');
        $update->execute(password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)
            ? [hash('sha256', $token), password_hash($password, PASSWORD_DEFAULT), $user['id']] : [hash('sha256', $token), $user['id']]);
        $_SESSION = ['staff_user' => $user['username'], 'staff_user_id' => (int) $user['id'], 'staff_name' => $user['display_name'],
            'staff_role' => $user['role'], 'staff_token' => $token, 'staff_seen' => time(), 'staff_csrf' => bin2hex(random_bytes(32))];
    } else {
        $_SESSION = ['staff_user' => $config['username'], 'staff_name' => 'Setup administrator', 'staff_role' => 'admin', 'staff_seen' => time(),
            'staff_fingerprint' => hash('sha256', $config['username'] . "\0" . $config['password_hash']), 'staff_csrf' => bin2hex(random_bytes(32))];
    }
}

function medivendStaffLogout(): void
{
    if (isset($_SESSION['staff_user_id'], $_SESSION['staff_token'])) {
        $clear = medivendDatabase()->prepare('UPDATE staff_users SET session_token_hash = NULL WHERE id = ? AND session_token_hash = ?');
        $clear->execute([$_SESSION['staff_user_id'], hash('sha256', (string) $_SESSION['staff_token'])]);
    }
    medivendStaffClear();
}

function medivendStaffError(Throwable $error): void
{
    if ($error instanceof MedivendCheckoutError) {
        http_response_code($error->status);
        $message = $error->getMessage(); $code = $error->errorCode;
    } else {
        error_log('[MediVend staff] ' . $error->getMessage());
        http_response_code(503);
        $message = 'Staff service is temporarily unavailable. Keep any pending change and retry the same request.';
        $code = 'staff_unavailable';
    }
    echo json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
