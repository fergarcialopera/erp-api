<?php

declare(strict_types=1);

namespace Tests\Integration\Lockers;

use Tests\Integration\Support\BaseApiTestCase;

final class LockersTreeEndpointTest extends BaseApiTestCase
{
    private const LOCKER_A1 = '40000000-0000-4000-8000-000000000001';
    private const LOCKER_B1 = '40000000-0000-4000-8000-000000000002';

    public function testListLockersTreeRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/lockers/tree');
        $this->assertSame(401, $res['status']);
    }

    public function testListLockersTreeReturnsNestedCompartmentsForClinic(): void
    {
        $res = $this->request('GET', '/api/v1/lockers/tree', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $res['status']);

        $data = $res['json']['data'] ?? null;
        $this->assertIsArray($data);

        $lockerIds = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $data);
        $this->assertContains(self::LOCKER_A1, $lockerIds);
        $this->assertNotContains(self::LOCKER_B1, $lockerIds);

        $locker = null;
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['id'] ?? '') === self::LOCKER_A1) {
                $locker = $row;
                break;
            }
        }
        $this->assertIsArray($locker);
        $compartments = $locker['compartments'] ?? null;
        $this->assertIsArray($compartments);
        $this->assertCount(3, $compartments);

        foreach ($compartments as $compartment) {
            $this->assertIsArray($compartment);
            $this->assertSame(self::LOCKER_A1, (string) ($compartment['locker_id'] ?? ''));
            $this->assertNotSame('', (string) ($compartment['code'] ?? ''));
        }
    }

    public function testListLockersTreeActiveFilter(): void
    {
        $locker = $this->request(
            'POST',
            '/api/v1/lockers',
            ['name' => 'Inactive-' . bin2hex(random_bytes(2))],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $locker['status']);
        $lockerId = (string) ($locker['json']['data']['id'] ?? '');

        $deactivate = $this->request(
            'DELETE',
            '/api/v1/lockers/' . $lockerId,
            null,
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(200, $deactivate['status']);

        $comp = $this->request(
            'POST',
            '/api/v1/compartments',
            ['locker_id' => $lockerId, 'code' => 'X-' . bin2hex(random_bytes(2))],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $comp['status']);

        $all = $this->request('GET', '/api/v1/lockers/tree', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $all['status']);
        $allIds = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            (array) ($all['json']['data'] ?? [])
        );
        $this->assertContains($lockerId, $allIds);

        $activeOnly = $this->request(
            'GET',
            '/api/v1/lockers/tree?active=true',
            null,
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(200, $activeOnly['status']);
        $activeIds = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            (array) ($activeOnly['json']['data'] ?? [])
        );
        $this->assertNotContains($lockerId, $activeIds);

        $invalid = $this->request(
            'GET',
            '/api/v1/lockers/tree?active=maybe',
            null,
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(422, $invalid['status']);
    }

    public function testListLockersTreeIsolationByClinic(): void
    {
        $res = $this->request('GET', '/api/v1/lockers/tree', null, $this->authHeaderFor('admin2@clinic.local'));
        $this->assertSame(200, $res['status']);

        $ids = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            (array) ($res['json']['data'] ?? [])
        );
        $this->assertNotContains(self::LOCKER_A1, $ids);
    }
}
