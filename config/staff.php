<?php
declare(strict_types=1);
require_once __DIR__ . '/environment.php';

// No built-in staff account. Configure these only in the private environment.
return [
    'username' => getenv('MEDIVEND_STAFF_USERNAME') ?: '',
    'password_hash' => getenv('MEDIVEND_STAFF_PASSWORD_HASH') ?: '',
    'rate_directory' => getenv('MEDIVEND_STAFF_RATE_DIR') ?: sys_get_temp_dir() . '/medivend-staff-rate',
    'idle_timeout_seconds' => 900,
];
