<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function medivendSetupPrivatePath(string $path): string
{
    if ($path === '' || is_link($path) || is_dir($path)) throw new RuntimeException('Choose a regular private environment file.');
    $parent = realpath(dirname($path));
    if (!$parent || !is_dir($parent) || !is_writable($parent)) throw new RuntimeException('The private environment directory must already exist and be writable.');
    $resolved = str_replace('\\', '/', $parent . DIRECTORY_SEPARATOR . basename($path));
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    if (PHP_OS_FAMILY === 'Windows') { $resolvedCompare = strtolower($resolved); $root = strtolower($root); }
    else $resolvedCompare = $resolved;
    if ($resolvedCompare === $root || strpos($resolvedCompare, $root . '/') === 0) {
        throw new RuntimeException('The staff environment file must be outside the web project.');
    }
    return $resolved;
}

function medivendSetupSecret(string $prompt): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        throw new RuntimeException('For hidden entry on Windows, pipe the password twice on standard input with --password-stdin, or run this setup over SSH on the Orange Pi.');
    }
    $tty = fopen('/dev/tty', 'r+');
    if (!$tty) throw new RuntimeException('No interactive terminal. Use --password-stdin to provide the password and confirmation as two lines.');
    $state = shell_exec('stty -g < /dev/tty');
    if (!is_string($state) || !preg_match('/\A[0-9a-fA-F:;]+\s*\z/', $state)) throw new RuntimeException('Unable to hide password input.');
    fwrite($tty, $prompt);
    exec('stty -echo < /dev/tty', $unused, $status);
    if ($status !== 0) throw new RuntimeException('Unable to hide password input.');
    try { $value = fgets($tty, 1026); }
    finally {
        exec('stty ' . escapeshellarg(trim($state)) . ' < /dev/tty');
        fwrite($tty, PHP_EOL);
        fclose($tty);
    }
    if ($value === false) throw new RuntimeException('Password entry cancelled.');
    return rtrim($value, "\r\n");
}

try {
    $args = getopt('', ['username:', 'env-file:', 'password-stdin', 'help']);
    if (isset($args['help'])) {
        echo "Usage: php scripts/setup-staff.php --username staff --env-file /etc/medivend/medivend.env\n";
        echo "Password is entered twice with input hidden. --password-stdin reads two password lines from stdin.\n";
        exit;
    }
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/\A--(?:password|password-hash)(?:=|\z)/', $argument)) {
            throw new RuntimeException('Do not put passwords on the command line. Use the hidden prompts or --password-stdin.');
        }
    }
    $username = $args['username'] ?? '';
    if (!is_string($username) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}\z/', $username) !== 1) {
        throw new RuntimeException('Provide --username with 3 to 80 letters, numbers, dots, underscores or hyphens.');
    }
    $path = medivendSetupPrivatePath($args['env-file'] ?? (getenv('MEDIVEND_ENV_FILE') ?: '/etc/medivend/medivend.env'));
    if (isset($args['password-stdin'])) {
        $password = rtrim((string) fgets(STDIN, 1026), "\r\n");
        $confirmation = rtrim((string) fgets(STDIN, 1026), "\r\n");
    } else {
        $password = medivendSetupSecret('New staff password: ');
        $confirmation = medivendSetupSecret('Repeat staff password: ');
    }
    if (!hash_equals($password, $confirmation)) throw new RuntimeException('The passwords do not match. Nothing was changed.');
    if (strlen($password) < 12 || strlen($password) > 72 || strpos($password, "\0") !== false) {
        throw new RuntimeException('Choose a password of 12 to 72 bytes. Nothing was changed.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    unset($password, $confirmation);
    if (!$hash) throw new RuntimeException('Unable to hash staff password. Nothing was changed.');
    $old = is_file($path) ? file_get_contents($path) : '';
    if ($old === false || ($old !== '' && parse_ini_string($old, false, INI_SCANNER_RAW) === false)) {
        throw new RuntimeException('Existing private environment is unreadable or malformed. Nothing was changed.');
    }
    $lines = preg_split('/\r\n|\n|\r/', $old);
    $lines = array_filter($lines, static fn(string $line): bool => preg_match('/^\s*MEDIVEND_STAFF_(?:USERNAME|PASSWORD_HASH)\s*=/', $line) !== 1);
    $updated = rtrim(implode(PHP_EOL, $lines)) . PHP_EOL
        . 'MEDIVEND_STAFF_USERNAME="' . $username . '"' . PHP_EOL
        . 'MEDIVEND_STAFF_PASSWORD_HASH="' . $hash . '"' . PHP_EOL;
    $previousMask = umask(0077);
    $temporary = tempnam(dirname($path), '.medivend-staff-');
    if ($temporary === false) throw new RuntimeException('Unable to create a private temporary file.');
    try {
        if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)) throw new RuntimeException('Could not write private configuration.');
        if (PHP_OS_FAMILY !== 'Windows') {
            if (is_file($path)) {
                $owner = fileowner($path); $group = filegroup($path);
                if ($owner === false || $group === false || !chown($temporary, $owner) || !chgrp($temporary, $group)) {
                    throw new RuntimeException('Could not retain private configuration ownership.');
                }
                $mode = fileperms($path);
                if ($mode === false || ($mode & 0007) !== 0) throw new RuntimeException('Private configuration must not be readable by other users. Set mode 0640 first.');
                if (!chmod($temporary, $mode & 0770)) throw new RuntimeException('Could not retain private file permissions.');
            } else {
                // For a newly created file, the administrator must arrange its
                // read-only web/service group before it can be used by Apache.
                if (!chmod($temporary, 0600)) throw new RuntimeException('Could not protect private configuration.');
            }
        }
        if (!rename($temporary, $path)) throw new RuntimeException('Could not replace private environment file.');
        $temporary = null;
    } finally {
        umask($previousMask);
        if ($temporary !== null && is_file($temporary)) unlink($temporary);
    }
    echo "Staff credentials saved. Existing database and machine settings were preserved.\n";
    echo "Username: " . $username . "\n";
    echo "Restart Apache after changing the private environment. On a new file, grant only Apache/service group read access before use.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Staff setup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
