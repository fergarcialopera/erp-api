<?php

declare(strict_types=1);

namespace Tests\Integration\EntryLogs;

use Tests\Integration\Support\BaseApiTestCase;

final class EntryLogsEndpointTest extends BaseApiTestCase
{
    private const ZONE_A1 = '50000000-0000-4000-8000-000000000001';
    private const AMBIENTE_A1 = '40000000-0000-4000-8000-000000000001';
    private const AMBIENTE_A2 = '40000000-0000-4000-8000-000000000002';

    private function createProductSkuForClinicA(): string
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'Producto ' . bin2hex(random_bytes(2))],
            $this->authHeaderFor('tech@clinic-erp.com')
        );
        $this->assertSame(201, $created['status']);
        $sku = (string) ($created['json']['data']['sku'] ?? '');
        $this->assertNotSame('', $sku);
        return $sku;
    }

    public function testCreateEntryLogWithoutTokenReturns401(): void
    {
        $res = $this->request('POST', '/api/v1/entry-logs', ['sku' => 'X', 'quantity' => 1]);
        $this->assertSame(401, $res['status']);
    }

    public function testCreateEntryLogRequiresTechnicianOrAdmin(): void
    {
        $sku = $this->createProductSkuForClinicA();

        $staff = $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Base', 'quantity' => 3], $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Base', 'quantity' => 3], $this->authHeaderFor('tech@clinic-erp.com'));
        $this->assertSame(201, $tech['status']);
        $this->assertArrayHasKey('data', $tech['json'] ?? []);
    }

    public function testCreateEntryLogValidation(): void
    {
        $res = $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => '', 'quantity' => 0],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(422, $res['status']);
    }

    public function testEntryLogsAreIsolatedByClinic(): void
    {
        $sku = $this->createProductSkuForClinicA();
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Tenant A', 'quantity' => 5], $this->authHeaderFor('admin@clinic-erp.com'));

        $listA = $this->request('GET', '/api/v1/entry-logs', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $listB = $this->request('GET', '/api/v1/entry-logs', null, $this->authHeaderFor('staff2@clinic-erp.com'));

        $hasInA = false;
        $hasInB = false;
        foreach (($listA['json']['data'] ?? []) as $row) {
            if (($row['sku'] ?? '') === $sku) {
                $hasInA = true;
            }
        }
        foreach (($listB['json']['data'] ?? []) as $row) {
            if (($row['sku'] ?? '') === $sku) {
                $hasInB = true;
            }
        }

        $this->assertTrue($hasInA);
        $this->assertFalse($hasInB);
    }

    public function testCreateEntryLogWithZoneReturnsLocation(): void
    {
        $sku = $this->createProductSkuForClinicA();
        $res = $this->request(
            'POST',
            '/api/v1/entry-logs',
            [
                'sku' => $sku,
                'quantity' => 2,
                'zone_id' => self::ZONE_A1,
                'ambiente_id' => self::AMBIENTE_A1,
            ],
            $this->authHeaderFor('tech@clinic-erp.com')
        );
        $this->assertSame(201, $res['status'], $res['raw'] ?? '');
        $entry = $res['json']['data']['entry_log'] ?? null;
        $this->assertIsArray($entry);
        $this->assertSame(self::ZONE_A1, $entry['zone']['id'] ?? null);
        $this->assertSame(self::AMBIENTE_A1, $entry['ambiente']['id'] ?? null);
        $this->assertSame('A1-C1', $entry['zone']['code'] ?? null);

        $list = $this->request('GET', '/api/v1/entry-logs', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $found = null;
        foreach (($list['json']['data'] ?? []) as $row) {
            if (($row['sku'] ?? '') === $sku && (int) ($row['quantity'] ?? 0) === 2) {
                $found = $row;
                break;
            }
        }
        $this->assertIsArray($found);
        $this->assertSame(self::ZONE_A1, $found['zone']['id'] ?? null);
    }

    public function testCreateEntryLogAmbienteWithoutZoneReturns422(): void
    {
        $sku = $this->createProductSkuForClinicA();
        $res = $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => $sku, 'quantity' => 1, 'ambiente_id' => self::AMBIENTE_A1],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(422, $res['status']);
    }

    public function testCreateEntryLogAmbienteZoneMismatchReturns422(): void
    {
        $sku = $this->createProductSkuForClinicA();
        $res = $this->request(
            'POST',
            '/api/v1/entry-logs',
            [
                'sku' => $sku,
                'quantity' => 1,
                'zone_id' => self::ZONE_A1,
                'ambiente_id' => self::AMBIENTE_A2,
            ],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(422, $res['status']);
    }
}
