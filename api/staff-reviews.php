<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/reviews.php';
medivendStaffHeaders();
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new MedivendCheckoutError(405, 'method_not_allowed', 'Use GET to list reviews or POST to resolve one.');
    }
    medivendStaffStart();
    $actor = medivendRequireStaff();
    $database = medivendDatabase();
    $result = [];
    if ($method === 'POST') {
        medivendStaffCsrf();
        $payload = medivendStaffPayload();
        $kind = $payload->kind ?? null;
        if ($kind === 'order' && medivendStaffKeys($payload, ['kind', 'event_id', 'reference', 'action', 'note'])
            && is_string($payload->event_id) && is_string($payload->reference) && is_string($payload->action)) {
            $resolved = medivendResolveOrder($database, $payload->event_id, $payload->reference, $payload->action, $payload->note, $actor);
            $result['resolved'] = ['reference' => $payload->reference, 'action' => $payload->action, 'replayed' => $resolved['replayed']];
        } elseif ($kind === 'coin' && medivendStaffKeys($payload, ['kind', 'event_id', 'action', 'note'])
            && is_string($payload->event_id) && is_string($payload->action)) {
            medivendResolveCoin($database, $payload->event_id, $payload->action, $payload->note, $actor);
            $result['resolved'] = ['coin' => $payload->event_id, 'action' => $payload->action];
        } else throw new MedivendCheckoutError(422, 'invalid_request', 'Send a valid resolution.');
    }
    $result += medivendReviewQueue($database);
    $result['csrf_token'] = $_SESSION['staff_csrf'];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) { medivendStaffError($error); }
