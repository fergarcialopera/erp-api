<?php

declare(strict_types=1);

namespace Tests\Integration\EntryLogs;

use Tests\Integration\Support\BaseApiTestCase;

final class EntryLogsEndpointTest extends BaseApiTestCase
{
    public function testCreateEntryLogWithoutTokenReturns401(): void
    {
        $res = $this->request('POST', '/api/v1/entry-logs', ['sku' => 'X', 'quantity' => 1]);
        $this->assertSame(401, $res['status']);
    }

    public function testCreateEntryLogRequiresTechnicianOrAdmin(): void
    {
        $sku = $this->uniqueSku();

        $staff = $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Base', 'quantity' => 3], $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Base', 'quantity' => 3], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $tech['status']);
        $this->assertArrayHasKey('data', $tech['json'] ?? []);
    }

    public function testCreateEntryLogValidation(): void
    {
        $res = $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => '', 'quantity' => 0],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(422, $res['status']);
    }

    public function testEntryLogsAreIsolatedByClinic(): void
    {
        $sku = $this->uniqueSku();
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Tenant A', 'quantity' => 5], $this->authHeaderFor('admin@clinic.local'));

        $listA = $this->request('GET', '/api/v1/entry-logs', null, $this->authHeaderFor('staff@clinic.local'));
        $listB = $this->request('GET', '/api/v1/entry-logs', null, $this->authHeaderFor('staff2@clinic.local'));

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
}
