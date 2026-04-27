<?php

declare(strict_types=1);

namespace Tests\Integration\ExitLogs;

use Tests\Integration\Support\BaseApiTestCase;

final class OpenExitLogLockEndpointTest extends BaseApiTestCase
{
    public function testOpenLockRequiresAuth(): void
    {
        $res = $this->request('POST', '/api/v1/exit-logs/1/open-lock');
        $this->assertSame(401, $res['status']);
    }

    public function testOpenLockReturns404WhenExitLogMissing(): void
    {
        $missingId = '10000000-0000-4000-8000-000000000001';
        $res = $this->request('POST', '/api/v1/exit-logs/' . $missingId . '/open-lock', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(404, $res['status']);
    }

    public function testOpenLockReturns422WhenNoCompartmentLinked(): void
    {
        $sku = $this->uniqueSku();
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Item', 'quantity' => 10], $this->authHeaderFor('admin@clinic.local'));
        $created = $this->request('POST', '/api/v1/exit-logs', ['sku' => $sku, 'quantity' => 1], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');
        $this->assertNotSame('', $exitId);

        $open = $this->request('POST', '/api/v1/exit-logs/' . $exitId . '/open-lock', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(422, $open['status']);
    }

    public function testOpenLockSuccessWithCompartmentAndDevice(): void
    {
        $locker = $this->request('POST', '/api/v1/lockers', ['name' => 'L-' . bin2hex(random_bytes(2))], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $locker['status']);
        $lockerId = (string) ($locker['json']['data']['id'] ?? '');
        $this->assertNotSame('', $lockerId);

        $deviceId = 'dev-' . bin2hex(random_bytes(4));
        $patch = $this->request('PATCH', '/api/v1/lockers/' . $lockerId, ['device_id' => $deviceId], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(200, $patch['status']);

        $comp = $this->request('POST', '/api/v1/compartments', ['locker_id' => $lockerId, 'code' => 'C-' . bin2hex(random_bytes(2))], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $comp['status']);
        $compartmentId = (string) ($comp['json']['data']['id'] ?? '');
        $this->assertNotSame('', $compartmentId);

        $sku = $this->uniqueSku();
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Item', 'quantity' => 10], $this->authHeaderFor('admin@clinic.local'));
        $created = $this->request('POST', '/api/v1/exit-logs', [
            'sku' => $sku,
            'quantity' => 1,
            'compartment_public_id' => $compartmentId,
        ], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');
        $this->assertNotSame('', $exitId);

        $open = $this->request('POST', '/api/v1/exit-logs/' . $exitId . '/open-lock', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $open['status'], $open['raw'] ?? '');
        $this->assertIsArray($open['json']);
        $data = $open['json']['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame($exitId, $data['exit_log_id'] ?? null);
        $this->assertSame($deviceId, $data['device_id'] ?? null);
        $this->assertSame('open', $data['payload'] ?? null);
        $this->assertSame('lockers/' . $deviceId . '/cmd', $data['topic'] ?? null);
    }
}
