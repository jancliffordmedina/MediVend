<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/users.php';

/*
 * DEMO ONLY: creates dummy accounts with publicly known passwords for local
 * testing. Refuses to run in hardware mode. Re-running skips existing accounts.
 *   php scripts/seed-demo-users.php            create the demo accounts
 *   php scripts/seed-demo-users.php --remove   deactivate them again
 */
const MEDIVEND_DEMO_USERS = [
    ['admin',         'Demo Administrator', 'admin', 'MediVend-Admin1'],
    ['nurse.santos',  'Nurse Santos (demo)', 'staff', 'MediVend-Staff1'],
    ['clinic.staff',  'Clinic Staff (demo)', 'staff', 'MediVend-Staff2'],
];

try {
    if (medivendMachineConfig()['mode'] === 'hardware') {
        throw new RuntimeException('Refusing to create demo accounts with known passwords in hardware mode. Use scripts/create-user.php.');
    }
    $database = medivendDatabase();
    if (in_array('--remove', $argv, true)) {
        $deactivate = $database->prepare("UPDATE staff_users SET is_active = 0, session_token_hash = NULL WHERE username = ? AND created_by = 'demo seed'");
        foreach (MEDIVEND_DEMO_USERS as [$username]) { $deactivate->execute([$username]); echo ($deactivate->rowCount() ? 'Deactivated ' : 'Not a demo account / absent: ') . $username . PHP_EOL; }
        exit(0);
    }
    foreach (MEDIVEND_DEMO_USERS as [$username, $name, $role, $password]) {
        try {
            medivendCreateUser($database, $username, $name, $role, $password, 'demo seed');
            printf("Created  %-13s %-6s password: %s\n", $username, $role, $password);
        } catch (MedivendCheckoutError $error) {
            if ($error->errorCode !== 'username_taken') throw $error;
            printf("Exists   %-13s (unchanged)\n", $username);
        }
    }
    echo "DEMO accounts only. Change these passwords or run with --remove before real use.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Not seeded: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
