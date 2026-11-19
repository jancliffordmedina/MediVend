<?php
declare(strict_types=1);
require_once __DIR__ . '/hardware-adapter.php';
require_once __DIR__ . '/machine.php';

/**
 * MedivendHardwareAdapter backed by the local Python service (medivend-hwd).
 * Loaded only by the CLI worker through the private adapter file, e.g.
 *   <?php require '/srv/medivend/includes/hardware-http-adapter.php';
 *   return new MedivendHttpHardwareAdapter('http://127.0.0.1:8765', '/etc/medivend/hwd.token');
 *
 * Safety rules enforced here, in addition to the service's own checks:
 *  - Real hardware mode refuses a simulated service; device_simulation refuses
 *    a real one. A mismatch reports connected = false (fail closed).
 *  - Every request has short connect/total timeouts. Failures throw; the worker
 *    treats that as uncertain, stops and requests staff. Nothing is retried here.
 *  - Only well-formed observations are yielded; anything else throws so the
 *    event stays unacknowledged in the device journal for staff.
 */
final class MedivendHttpHardwareAdapter implements MedivendHardwareAdapter
{
    private string $baseUrl;
    private string $token;
    private bool $expectSimulated;

    public function __construct(string $baseUrl, string $tokenFile, ?bool $expectSimulated = null)
    {
        $parts = parse_url($baseUrl);
        if (!$parts || ($parts['scheme'] ?? '') !== 'http' || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)) {
            throw new RuntimeException('Hardware service URL must be a loopback http:// address.');
        }
        if (!is_file($tokenFile) || !is_readable($tokenFile)) throw new RuntimeException('Hardware service token is unreadable.');
        $token = trim((string) file_get_contents($tokenFile));
        if (strlen($token) < 32) throw new RuntimeException('Hardware service token is too short.');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->expectSimulated = $expectSimulated ?? medivendHardwareInventoryDemo();
    }

    /** @return array{0:int,1:array} */
    private function request(string $method, string $path, ?array $body = null, int $timeoutMs = 2000): array
    {
        $curl = curl_init($this->baseUrl . '/v1/' . $path);
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body === [] ? new stdClass() : $body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);
        $text = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($text === false) throw new RuntimeException('Hardware service unreachable: ' . $error);
        $payload = json_decode((string) $text, true);
        if (!is_array($payload) || !is_bool($payload['ok'] ?? null)) throw new RuntimeException('Hardware service returned an invalid response (HTTP ' . $status . ').');
        return [$status, $payload];
    }

    public function health(): array
    {
        try {
            [$status, $payload] = $this->request('GET', 'health');
        } catch (Throwable $error) {
            error_log('[MediVend adapter] ' . $error->getMessage());
            return ['connected' => false];
        }
        $health = $status === 200 && $payload['ok'] ? ($payload['data'] ?? []) : [];
        if (!is_array($health) || ($health['simulated'] ?? null) !== $this->expectSimulated) {
            if ($health) error_log('[MediVend adapter] Hardware service simulation flag does not match the server mode; refusing it.');
            return ['connected' => false];
        }
        // A PHP delivery deadline shorter than one device cycle would time out
        // genuine deliveries and push every order to staff review.
        $timeout = medivendMachineConfig()['delivery_timeout_seconds'];
        $cycle = $health['details']['max_cycle_ms'] ?? null;
        if (!is_int($cycle) || $timeout * 1000 < $cycle + 2000) {
            error_log('[MediVend adapter] MEDIVEND_DELIVERY_TIMEOUT_SECONDS must exceed the device cycle (' . var_export($cycle, true) . ' ms) by at least 2 s.');
            return ['connected' => false];
        }
        return $health;
    }

    public function verifiedEvents(): iterable
    {
        [$status, $payload] = $this->request('GET', 'events?limit=50');
        if ($status !== 200 || !$payload['ok'] || !is_array($payload['data']['events'] ?? null)) throw new RuntimeException('Hardware events unavailable.');
        foreach ($payload['data']['events'] as $event) {
            $type = $event['type'] ?? null; $id = $event['event_id'] ?? null;
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/', $id) || !is_int($event['observed_at'] ?? null)) throw new RuntimeException('Malformed hardware event; it remains in the device journal.');
            if ($type === 'accepted_coin' && is_int($event['coin_centavos'] ?? null)) {
                yield ['type' => 'accepted_coin', 'event_id' => $id, 'coin_centavos' => $event['coin_centavos'], 'observed_at' => $event['observed_at']];
            } elseif ($type === 'delivery' && is_string($event['command_id'] ?? null)
                && in_array($event['outcome'] ?? null, ['sensor_confirmed', 'jam', 'timeout', 'uncertain'], true)) {
                yield ['type' => 'delivery', 'event_id' => $id, 'command_id' => $event['command_id'], 'outcome' => $event['outcome']];
            } else throw new RuntimeException('Unknown hardware event; it remains in the device journal.');
        }
    }

    public function acknowledge(string $eventId): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $eventId)) throw new RuntimeException('Invalid event ID.');
        [$status, $payload] = $this->request('POST', 'events/' . $eventId . '/ack', []);
        if ($status !== 200 || !$payload['ok']) throw new RuntimeException('Hardware event acknowledgement failed.');
    }

    public function dispatch(array $command): void
    {
        [$status, $payload] = $this->request('POST', 'dispense', [
            'command_id' => (string) $command['id'],
            'compartment' => (int) $command['compartment_number'],
            'unit_number' => (int) $command['unit_number'],
        ], 3000);
        // 202 = accepted now; 200 = the device already has this command ID
        // (deduplicated, never actuated twice). Anything else is uncertain.
        if (!in_array($status, [200, 202], true) || !$payload['ok']) {
            throw new RuntimeException('Dispense not accepted: ' . ($payload['error']['code'] ?? ('HTTP ' . $status)));
        }
    }

    /** Tester controls for the SIMULATED device only (coin / beam scenario). */
    public function simulate(string $what, array $body): array
    {
        if (!$this->expectSimulated || !in_array($what, ['coin', 'reject', 'beam'], true)) throw new RuntimeException('Simulator controls are only available in device_simulation mode.');
        $health = $this->health();
        if (($health['simulated'] ?? false) !== true) throw new RuntimeException('The hardware service is not running its simulator.');
        [$status, $payload] = $this->request('POST', 'sim/' . $what, $body);
        if (!in_array($status, [200, 202], true) || !$payload['ok']) throw new RuntimeException($payload['error']['message'] ?? 'Simulator request failed.');
        return $payload['data'];
    }

    public function stop(): void
    {
        [$status, $payload] = $this->request('POST', 'stop', ['reason' => 'PHP worker stop']);
        if ($status !== 200 || !$payload['ok']) throw new RuntimeException('Hardware stop could not be confirmed.');
    }
}
