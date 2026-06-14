<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use Tests\Integration\Support\BaseApiTestCase;

final class InventoryEndpointTest extends BaseApiTestCase
{
    private function createProductSku(string $namePrefix): string
    {
        $product = $this->createProductVisibleInClinicA($namePrefix . '-' . bin2hex(random_bytes(2)));

        return $product['sku'];
    }

    private function productIdForSku(string $sku): string
    {
        $pdo = self::testPdo();
        $stmt = $pdo->prepare('SELECT id::text AS id FROM products WHERE sku = :sku LIMIT 1');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $id = (string) ($row['id'] ?? '');
        $this->assertNotSame('', $id);

        return $id;
    }

    public function testListInventoryWithoutTokenReturns401(): void
    {
        $res = $this->request('GET', '/api/v1/inventory');
        $this->assertSame(401, $res['status']);
    }

    public function testInventoryIsIsolatedByClinic(): void
    {
        $skuA = $this->createProductSku('A');
        $productB = $this->createProductVisibleInClinicA('B-' . bin2hex(random_bytes(2)));
        $visibleB = $this->request(
            'PATCH',
            '/api/v1/clinic/products/' . $productB['id'],
            ['visible' => true],
            $this->authHeaderFor('admin2@clinic-erp.com')
        );
        $this->assertSame(200, $visibleB['status']);
        $skuB = $productB['sku'];

        $this->request('POST', '/api/v1/entry-logs', ['sku' => $skuA, 'name' => 'A', 'quantity' => 11], $this->authHeaderFor('admin@clinic-erp.com'));
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $skuB, 'name' => 'B', 'quantity' => 22], $this->authHeaderFor('admin2@clinic-erp.com'));

        $clinicA = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $clinicB = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff2@clinic-erp.com'));

        $dataA = $clinicA['json']['data'] ?? [];
        $dataB = $clinicB['json']['data'] ?? [];

        $containsAinA = false;
        $containsAinB = false;
        foreach ($dataA as $row) {
            if (($row['product']['sku'] ?? '') === $skuA) {
                $containsAinA = true;
            }
        }
        foreach ($dataB as $row) {
            if (($row['product']['sku'] ?? '') === $skuA) {
                $containsAinB = true;
            }
        }

        $this->assertTrue($containsAinA);
        $this->assertFalse($containsAinB);
    }

    public function testInventoryIncludesLocationsWithIdsAndNames(): void
    {
        $clinicAId = '11111111-1111-1111-1111-111111111111';

        $sku = $this->createProductSku('LOC');
        $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => $sku, 'name' => 'LOC', 'quantity' => 11],
            $this->authHeaderFor('admin@clinic-erp.com')
        );

        $productId = $this->productIdForSku($sku);
        $location = $this->insertAmbienteAndZoneForClinic($clinicAId, 'Ambiente Test', 'C-TEST');
        $ambienteId = $location['ambiente_id'];
        $zoneId = $location['zone_id'];

        $invStmt = self::testPdo()->prepare(
            'INSERT INTO inventory_items (clinic_id, product_id, zone_id, quantity, updated_at)
             VALUES (:clinic_id, :product_id, :zone_id, :quantity, NOW())'
        );
        $invStmt->execute([
            'clinic_id' => $clinicAId,
            'product_id' => $productId,
            'zone_id' => $zoneId,
            'quantity' => 5,
        ]);

        $res = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'] ?? [];
        $this->assertIsArray($data);

        $row = null;
        foreach ($data as $r) {
            if (($r['product']['sku'] ?? '') === $sku) {
                $row = $r;
                break;
            }
        }
        $this->assertIsArray($row);

        $this->assertSame($productId, (string) ($row['product']['id'] ?? ''));
        $this->assertSame($sku, (string) ($row['product']['sku'] ?? ''));
        $this->assertNotSame('', (string) ($row['product']['name'] ?? ''));
        $this->assertSame(16, (int) ($row['quantity_total'] ?? -1));

        $locations = $row['locations'] ?? null;
        $this->assertIsArray($locations);
        $this->assertGreaterThanOrEqual(2, count($locations));

        $foundAssigned = false;
        $foundUnassigned = false;
        foreach ($locations as $loc) {
            if (($loc['zone'] ?? null) === null) {
                $foundUnassigned = true;
                $this->assertSame(null, $loc['ambiente'] ?? null);
                continue;
            }

            if ((string) ($loc['zone']['id'] ?? '') === $zoneId) {
                $foundAssigned = true;
                $this->assertSame('C-TEST', (string) ($loc['zone']['code'] ?? ''));
                $this->assertSame($ambienteId, (string) ($loc['ambiente']['id'] ?? ''));
                $this->assertSame('Ambiente Test', (string) ($loc['ambiente']['name'] ?? ''));
            }
        }

        $this->assertTrue($foundAssigned);
        $this->assertTrue($foundUnassigned);
    }

    public function testPatchInventoryProductWithoutTokenReturns401(): void
    {
        $res = $this->request(
            'PATCH',
            '/api/v1/inventory/products/10000000-0000-4000-8000-000000000001',
            ['locations' => [['quantity' => 1]]]
        );
        $this->assertSame(401, $res['status']);
    }

    public function testPatchInventoryProductAsStaffReturns403(): void
    {
        $sku = $this->createProductSku('ADJ');
        $productId = $this->productIdForSku($sku);

        $res = $this->request(
            'PATCH',
            '/api/v1/inventory/products/' . $productId,
            ['locations' => [['quantity' => 9]]],
            $this->authHeaderFor('staff@clinic-erp.com')
        );
        $this->assertSame(403, $res['status']);
    }

    public function testPatchInventoryProductAsAdminSetsQuantitiesByLocation(): void
    {
        $clinicAId = '11111111-1111-1111-1111-111111111111';
        $sku = $this->createProductSku('ADJ-ADMIN');
        $productId = $this->productIdForSku($sku);

        $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => $sku, 'name' => 'ADJ-ADMIN', 'quantity' => 4],
            $this->authHeaderFor('admin@clinic-erp.com')
        );

        $location = $this->insertAmbienteAndZoneForClinic($clinicAId, 'Ambiente Adj', 'ADJ-C1');
        $zoneId = $location['zone_id'];

        $res = $this->request(
            'PATCH',
            '/api/v1/inventory/products/' . $productId,
            [
                'locations' => [
                    ['quantity' => 7, 'zone_id' => $zoneId],
                    ['quantity' => 2, 'zone_id' => null],
                ],
            ],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(200, $res['status']);

        $data = $res['json']['data'] ?? [];
        $this->assertSame($productId, (string) ($data['product']['id'] ?? ''));
        $this->assertSame(9, (int) ($data['quantity_total'] ?? -1));

        $locations = $data['locations'] ?? [];
        $this->assertIsArray($locations);

        $foundAssigned = false;
        $foundUnassigned = false;
        foreach ($locations as $loc) {
            if (($loc['zone']['id'] ?? null) === $zoneId) {
                $foundAssigned = true;
                $this->assertSame(7, (int) ($loc['quantity'] ?? -1));
            }
            if (($loc['zone'] ?? null) === null) {
                $foundUnassigned = true;
                $this->assertSame(2, (int) ($loc['quantity'] ?? -1));
            }
        }
        $this->assertTrue($foundAssigned);
        $this->assertTrue($foundUnassigned);

        $list = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $list['status']);
        $row = null;
        foreach ($list['json']['data'] ?? [] as $r) {
            if (($r['product']['id'] ?? '') === $productId) {
                $row = $r;
                break;
            }
        }
        $this->assertIsArray($row);
        $this->assertSame(9, (int) ($row['quantity_total'] ?? -1));
    }
}
