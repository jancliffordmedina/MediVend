<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/users.php';
medivendStaffHeaders();
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new MedivendCheckoutError(405, 'method_not_allowed', 'Use GET to list accounts or POST to change them.');
    }
    medivendStaffStart();
    $actor = medivendRequireAdmin();
    $actorId = $_SESSION['staff_user_id'] ?? null;
    $database = medivendDatabase();
    $result = [];
    if ($method === 'POST') {
        medivendStaffCsrf();
        $payload = medivendStaffPayload();
        $action = $payload->action ?? null;
        $string = static fn($value): bool => is_string($value) && strlen($value) <= 1024;
        if ($action === 'create' && medivendStaffKeys($payload, ['action', 'username', 'display_name', 'role', 'password'])
            && $string($payload->username) && $string($payload->display_name) && $string($payload->role) && $string($payload->password)) {
            $result['created'] = medivendCreateUser($database, trim($payload->username), $payload->display_name, $payload->role, $payload->password, $actor);
        } elseif ($action === 'update' && is_int($payload->id ?? null)) {
            $allowed = ['action', 'id', 'role', 'is_active', 'display_name', 'password'];
            $keys = array_keys(get_object_vars($payload));
            if (array_diff($keys, $allowed) || count($keys) < 3) throw new MedivendCheckoutError(422, 'invalid_request', 'Send a valid account change.');
            $changes = [];
            if (property_exists($payload, 'role')) { if (!is_string($payload->role)) throw new MedivendCheckoutError(422, 'invalid_role', 'Choose the admin or staff role.'); $changes['role'] = $payload->role; }
            if (property_exists($payload, 'is_active')) { if (!is_bool($payload->is_active)) throw new MedivendCheckoutError(422, 'invalid_request', 'Active must be true or false.'); $changes['is_active'] = $payload->is_active; }
            if (property_exists($payload, 'display_name')) { if (!$string($payload->display_name)) throw new MedivendCheckoutError(422, 'invalid_name', 'Enter a name.'); $changes['display_name'] = $payload->display_name; }
            if (property_exists($payload, 'password')) { if (!$string($payload->password)) throw new MedivendCheckoutError(422, 'invalid_password', 'Enter a password.'); $changes['password'] = $payload->password; }
            medivendUpdateUser($database, $actorId, $payload->id, $changes);
            $result['updated'] = $payload->id;
        } else throw new MedivendCheckoutError(422, 'invalid_request', 'Send a valid account request.');
    }
    $result['users'] = medivendListUsers($database);
    $result['self_id'] = $actorId;
    $result['csrf_token'] = $_SESSION['staff_csrf'];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) { medivendStaffError($error); }
