<?php
declare(strict_types=1);
/* Tester controls for MEDIVEND_MODE=device_simulation: insert a SIMULATED coin or
 * choose the next simulated beam outcome. Staff sign-in + CSRF required. Refused in
 * every other mode, and by the adapter whenever the device is not the simulator. */
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/hardware-http-adapter.php';
medivendStaffHeaders();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        throw new MedivendCheckoutError(405, 'method_not_allowed', 'Use POST for simulator controls.');
    }
    medivendStaffStart();
    medivendRequireStaff();
    medivendStaffCsrf();
    $config = medivendMachineConfig();
    if ($config['mode'] !== 'device_simulation') throw new MedivendCheckoutError(403, 'simulator_disabled', 'The simulator is only available in device simulation mode.');
    $payload = medivendStaffPayload();
    $adapter = medivendLoadHardwareAdapter($config);
    if (!$adapter instanceof MedivendHttpHardwareAdapter) throw new MedivendCheckoutError(503, 'simulator_unavailable', 'No simulated hardware service is configured.');
    if (($payload->action ?? null) === 'coin' && medivendStaffKeys($payload, ['action', 'centavos'])
        && is_int($payload->centavos) && in_array($payload->centavos, [100, 500, 1000, 2000], true)) {
        $result = $adapter->simulate('coin', ['centavos' => $payload->centavos]);
        $text = 'Simulated ₱' . ($payload->centavos / 100) . ' coin inserted.';
    } elseif (($payload->action ?? null) === 'beam' && medivendStaffKeys($payload, ['action', 'scenario'])
        && in_array($payload->scenario, ['pass', 'none', 'jam', 'double', 'late'], true)) {
        $result = $adapter->simulate('beam', ['scenario' => $payload->scenario]);
        $text = 'Next dispense will simulate: ' . $payload->scenario . '.';
    } else throw new MedivendCheckoutError(422, 'invalid_request', 'Choose a supported coin or beam outcome.');
    echo json_encode(['ok' => true, 'message' => $text, 'result' => $result, 'csrf_token' => $_SESSION['staff_csrf']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (!$error instanceof MedivendCheckoutError) {
        error_log('[MediVend simulator] ' . $error->getMessage());
        $error = new MedivendCheckoutError(503, 'simulator_unavailable', 'Simulator unavailable: ' . $error->getMessage() . ' Check that the hardware service (Window 1) is running.');
    }
    medivendStaffError($error);
}
