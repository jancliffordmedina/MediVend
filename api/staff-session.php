<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/staff.php';
medivendStaffHeaders();
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new MedivendCheckoutError(405, 'method_not_allowed', 'Use GET or POST for staff sessions.');
    }
    medivendStaffStart();
    if ($method === 'POST') {
        medivendStaffCsrf();
        $payload = medivendStaffPayload();
        if (($payload->action ?? null) === 'login' && medivendStaffKeys($payload, ['action', 'username', 'password'])
            && is_string($payload->username) && strlen($payload->username) <= 80
            && is_string($payload->password) && strlen($payload->password) <= 1024) {
            medivendStaffLogin($payload->username, $payload->password);
        } elseif (($payload->action ?? null) === 'logout' && medivendStaffKeys($payload, ['action'])) {
            medivendStaffLogout();
        } else throw new MedivendCheckoutError(422, 'invalid_request', 'Send a valid sign-in or sign-out request.');
    } elseif (isset($_SESSION['staff_user'])) {
        medivendRequireStaff();
    }
    echo json_encode(medivendStaffView(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) { medivendStaffError($error); }
