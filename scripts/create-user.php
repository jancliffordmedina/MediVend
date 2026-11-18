<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/users.php';

/*
 * Create a MediVend user account, or reset a password (e.g. if every admin is
 * locked out). Passwords are never accepted as command-line arguments.
 *
 *   php scripts/create-user.php --username admin --name "Clinic Admin" --role admin
 *   php scripts/create-user.php --username admin --reset-password
 *   Windows / scripts: add --password-stdin and pipe the password twice.
 */

function medivendCliSecret(string $prompt): string
{
    if (PHP_OS_FAMILY === 'Windows') throw new RuntimeException('On Windows, use --password-stdin and type the password twice (it will be visible), or run this over SSH on the Orange Pi.');
    $tty = fopen('/dev/tty', 'r+');
    if (!$tty) throw new RuntimeException('No interactive terminal. Use --password-stdin.');
    $state = shell_exec('stty -g < /dev/tty');
    if (!is_string($state) || !preg_match('/\A[0-9a-fA-F:;]+\s*\z/', $state)) throw new RuntimeException('Unable to hide password input.');
    fwrite($tty, $prompt);
    exec('stty -echo < /dev/tty', $unused, $status);
    if ($status !== 0) throw new RuntimeException('Unable to hide password input.');
    try { $value = fgets($tty, 1026); }
    finally { exec('stty ' . escapeshellarg(trim($state)) . ' < /dev/tty'); fwrite($tty, PHP_EOL); fclose($tty); }
    if ($value === false) throw new RuntimeException('Password entry cancelled.');
    return rtrim($value, "\r\n");
}

try {
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/\A--password(?:=|\z)/', $argument)) throw new RuntimeException('Do not put passwords on the command line. Use the prompt or --password-stdin.');
    }
    $args = getopt('', ['username:', 'name:', 'role:', 'reset-password', 'password-stdin', 'help']);
    if (isset($args['help']) || !isset($args['username'])) {
        echo "Create:  php scripts/create-user.php --username USER --name \"Full Name\" --role admin|staff [--password-stdin]\n";
        echo "Reset:   php scripts/create-user.php --username USER --reset-password [--password-stdin]\n";
        exit(isset($args['help']) ? 0 : 1);
    }
    $username = (string) $args['username'];
    medivendValidateUsername($username);
    if (isset($args['password-stdin'])) {
        if (PHP_OS_FAMILY === 'Windows') fwrite(STDERR, "Type the password, press Enter, then type it again and press Enter:\n");
        $password = rtrim((string) fgets(STDIN, 1026), "\r\n");
        $confirmation = rtrim((string) fgets(STDIN, 1026), "\r\n");
    } else {
        $password = medivendCliSecret('Password: ');
        $confirmation = medivendCliSecret('Repeat password: ');
    }
    if (!hash_equals($password, $confirmation)) throw new RuntimeException('The passwords do not match. Nothing was changed.');
    medivendValidatePassword($password);
    $database = medivendDatabase();
    if (isset($args['reset-password'])) {
        $find = $database->prepare('SELECT id FROM staff_users WHERE username = ?'); $find->execute([$username]);
        $id = $find->fetchColumn();
        if ($id === false) throw new RuntimeException('No account named ' . $username . '.');
        // Reactivates the account too, so a locked-out administrator can recover.
        $update = $database->prepare('UPDATE staff_users SET password_hash = ?, is_active = 1, session_token_hash = NULL WHERE id = ?');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        echo "Password reset for $username. Any existing sign-in for this account has ended.\n";
    } else {
        $role = (string) ($args['role'] ?? 'staff');
        $created = medivendCreateUser($database, $username, (string) ($args['name'] ?? $username), $role, $password, 'command line');
        echo "Created $role account " . $created['username'] . ". Sign in at staff.php (the kiosk's Staff login button).\n";
        if ($role === 'admin') echo "The legacy private-environment staff account, if any, no longer signs in now that an administrator exists.\n";
    }
    unset($password, $confirmation);
} catch (MedivendCheckoutError $error) {
    fwrite(STDERR, 'Not saved: ' . $error->getMessage() . PHP_EOL); exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Not saved: ' . $error->getMessage() . PHP_EOL); exit(1);
}
