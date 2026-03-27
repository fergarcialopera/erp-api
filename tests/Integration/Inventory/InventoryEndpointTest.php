<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use Tests\Integration\Support\BaseApiTestCase;

final class InventoryEndpointTest extends BaseApiTestCase
{
    public function testListInventoryWithoutTokenReturns401(): void
    {
        $res = $this->request('GET', '/api/v1/inventory');
        $this->assertSame(401, $res['status']);
    }

    public function testInventoryIsIsolatedByClinic(): void
    {
        $skuA = $this->uniqueSku('A');
        $skuB = $this->uniqueSku('B');

        $this->request('POST', '/api/v1/entry-logs', ['sku' => $skuA, 'name' => 'A', 'quantity' => 11], $this->authHeaderFor('admin@clinic.local'));
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $skuB, 'name' => 'B', 'quantity' => 22], $this->authHeaderFor('admin2@clinic.local'));

        $clinicA = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff@clinic.local'));
        $clinicB = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff2@clinic.local'));

        $dataA = $clinicA['json']['data'] ?? [];
        $dataB = $clinicB['json']['data'] ?? [];

        $containsAinA = false;
        $containsAinB = false;
        foreach ($dataA as $row) {
            if (($row['sku'] ?? '') === $skuA) {
                $containsAinA = true;
            }
        }
        foreach ($dataB as $row) {
            if (($row['sku'] ?? '') === $skuA) {
                $containsAinB = true;
            }
        }

        $this->assertTrue($containsAinA);
        $this->assertFalse($containsAinB);
    }
}
