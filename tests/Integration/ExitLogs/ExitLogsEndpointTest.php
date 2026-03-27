<?php

declare(strict_types=1);

namespace Tests\Integration\ExitLogs;

use Tests\Integration\Support\BaseApiTestCase;

final class ExitLogsEndpointTest extends BaseApiTestCase
{
    public function testCreateExitLogWithoutTokenReturns401(): void
    {
        $res = $this->request('POST', '/api/v1/exit-logs', ['sku' => 'X', 'quantity' => 1]);
        $this->assertSame(401, $res['status']);
    }

    public function testCreateExitLogRequiresTechnicianOrAdmin(): void
    {
        $sku = $this->uniqueSku();
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Base', 'quantity' => 20], $this->authHeaderFor('admin@clinic.local'));

        $staff = $this->request('POST', '/api/v1/exit-logs', ['sku' => $sku, 'quantity' => 3], $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/exit-logs', ['sku' => $sku, 'quantity' => 3], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $tech['status']);
    }

    public function testCreateExitLogInsufficientStock(): void
    {
        $sku = $this->uniqueSku();
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Base', 'quantity' => 1], $this->authHeaderFor('admin@clinic.local'));

        $res = $this->request('POST', '/api/v1/exit-logs', ['sku' => $sku, 'quantity' => 9999], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(422, $res['status']);
    }

    public function testExitLogsAreIsolatedByClinic(): void
    {
        $sku = $this->uniqueSku();
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $sku, 'name' => 'Tenant A', 'quantity' => 30], $this->authHeaderFor('admin@clinic.local'));
        $this->request('POST', '/api/v1/exit-logs', ['sku' => $sku, 'quantity' => 2], $this->authHeaderFor('admin@clinic.local'));

        $listA = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('staff@clinic.local'));
        $listB = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('staff2@clinic.local'));

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
