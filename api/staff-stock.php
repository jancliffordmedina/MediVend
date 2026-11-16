<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/stock.php';
medivendStaffHeaders();
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new MedivendCheckoutError(405, 'method_not_allowed', 'Use GET to view stock or POST to add stock.');
    }
    medivendStaffStart();
    $actor = medivendRequireStaff();
    $mode = medivendMachineConfig()['mode'];
    $database = medivendDatabase();
    $result = [];
    if ($method === 'POST') {
        medivendStaffCsrf();
        $request = medivendStockPayload(medivendStaffPayload());
        $result = medivendAddStock($database, $request, $actor, $mode);
    }
    $result += medivendStockView($database, $mode);
    $result['csrf_token'] = $_SESSION['staff_csrf'];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) { medivendStaffError($error); }
