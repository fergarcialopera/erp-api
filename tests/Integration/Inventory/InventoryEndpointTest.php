<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use Tests\Integration\Support\BaseApiTestCase;

final class InventoryEndpointTest extends BaseApiTestCase
{
    private function createProductSku(string $email, string $namePrefix): string
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => $namePrefix . '-' . bin2hex(random_bytes(2))],
            $this->authHeaderFor($email)
        );
        $this->assertSame(201, $created['status']);
        $sku = (string) ($created['json']['data']['sku'] ?? '');
        $this->assertNotSame('', $sku);
        return $sku;
    }

    private function productIdForSku(string $clinicId, string $sku): string
    {
        $pdo = self::testPdo();
        $stmt = $pdo->prepare('SELECT id::text AS id FROM products WHERE clinic_id = :clinic_id AND sku = :sku LIMIT 1');
        $stmt->execute(['clinic_id' => $clinicId, 'sku' => $sku]);
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
        $skuA = $this->createProductSku('tech@clinic.local', 'A');
        $skuB = $this->createProductSku('tech2@clinic.local', 'B');

        $this->request('POST', '/api/v1/entry-logs', ['sku' => $skuA, 'name' => 'A', 'quantity' => 11], $this->authHeaderFor('admin@clinic.local'));
        $this->request('POST', '/api/v1/entry-logs', ['sku' => $skuB, 'name' => 'B', 'quantity' => 22], $this->authHeaderFor('admin2@clinic.local'));

        $clinicA = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff@clinic.local'));
        $clinicB = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff2@clinic.local'));

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

        $sku = $this->createProductSku('tech@clinic.local', 'LOC');
        $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => $sku, 'name' => 'LOC', 'quantity' => 11],
            $this->authHeaderFor('admin@clinic.local')
        );

        $productId = $this->productIdForSku($clinicAId, $sku);

        $pdo = self::testPdo();
        $ambienteStmt = $pdo->prepare(
            'INSERT INTO ambientes (clinic_id, name, location, device_id, is_active, created_at, updated_at)
             VALUES (:clinic_id, :name, :location, :device_id, TRUE, NOW(), NOW())
             RETURNING id::text AS id, name'
        );
        $ambienteStmt->execute([
            'clinic_id' => $clinicAId,
            'name' => 'Ambiente Test',
            'location' => 'Planta X',
            'device_id' => 'DEV-' . bin2hex(random_bytes(3)),
        ]);
        $ambiente = $ambienteStmt->fetch();
        $this->assertIsArray($ambiente);
        $ambienteId = (string) ($ambiente['id'] ?? '');
        $this->assertNotSame('', $ambienteId);

        $compStmt = $pdo->prepare(
            'INSERT INTO compartments (clinic_id, ambiente_id, code, is_active, created_at, updated_at)
             VALUES (:clinic_id, :ambiente_id, :code, TRUE, NOW(), NOW())
             RETURNING id::text AS id, code'
        );
        $compStmt->execute([
            'clinic_id' => $clinicAId,
            'ambiente_id' => $ambienteId,
            'code' => 'C-TEST',
        ]);
        $comp = $compStmt->fetch();
        $this->assertIsArray($comp);
        $compartmentId = (string) ($comp['id'] ?? '');
        $this->assertNotSame('', $compartmentId);

        $invStmt = $pdo->prepare(
            'INSERT INTO inventory_items (clinic_id, product_id, compartment_id, quantity, updated_at)
             VALUES (:clinic_id, :product_id, :compartment_id, :quantity, NOW())'
        );
        $invStmt->execute([
            'clinic_id' => $clinicAId,
            'product_id' => $productId,
            'compartment_id' => $compartmentId,
            'quantity' => 5,
        ]);

        $res = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff@clinic.local'));
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
            if (($loc['compartment'] ?? null) === null) {
                $foundUnassigned = true;
                $this->assertSame(null, $loc['ambiente'] ?? null);
                continue;
            }

            if ((string) ($loc['compartment']['id'] ?? '') === $compartmentId) {
                $foundAssigned = true;
                $this->assertSame('C-TEST', (string) ($loc['compartment']['code'] ?? ''));
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
        $sku = $this->createProductSku('tech@clinic.local', 'ADJ');
        $productId = $this->productIdForSku('11111111-1111-1111-1111-111111111111', $sku);

        $res = $this->request(
            'PATCH',
            '/api/v1/inventory/products/' . $productId,
            ['locations' => [['quantity' => 9]]],
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(403, $res['status']);
    }

    public function testPatchInventoryProductAsAdminSetsQuantitiesByLocation(): void
    {
        $clinicAId = '11111111-1111-1111-1111-111111111111';
        $sku = $this->createProductSku('tech@clinic.local', 'ADJ-ADMIN');
        $productId = $this->productIdForSku($clinicAId, $sku);

        $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => $sku, 'name' => 'ADJ-ADMIN', 'quantity' => 4],
            $this->authHeaderFor('admin@clinic.local')
        );

        $pdo = self::testPdo();
        $ambienteStmt = $pdo->prepare(
            'INSERT INTO ambientes (clinic_id, name, location, device_id, is_active, created_at, updated_at)
             VALUES (:clinic_id, :name, :location, :device_id, TRUE, NOW(), NOW())
             RETURNING id::text AS id'
        );
        $ambienteStmt->execute([
            'clinic_id' => $clinicAId,
            'name' => 'Ambiente Adj',
            'location' => 'Planta',
            'device_id' => 'DEV-ADJ-' . bin2hex(random_bytes(2)),
        ]);
        $ambiente = $ambienteStmt->fetch();
        $this->assertIsArray($ambiente);
        $ambienteId = (string) ($ambiente['id'] ?? '');

        $compStmt = $pdo->prepare(
            'INSERT INTO compartments (clinic_id, ambiente_id, code, is_active, created_at, updated_at)
             VALUES (:clinic_id, :ambiente_id, :code, TRUE, NOW(), NOW())
             RETURNING id::text AS id'
        );
        $compStmt->execute([
            'clinic_id' => $clinicAId,
            'ambiente_id' => $ambienteId,
            'code' => 'ADJ-C1',
        ]);
        $comp = $compStmt->fetch();
        $this->assertIsArray($comp);
        $compartmentId = (string) ($comp['id'] ?? '');

        $res = $this->request(
            'PATCH',
            '/api/v1/inventory/products/' . $productId,
            [
                'locations' => [
                    ['quantity' => 7, 'compartment_id' => $compartmentId],
                    ['quantity' => 2, 'compartment_id' => null],
                ],
            ],
            $this->authHeaderFor('admin@clinic.local')
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
            if (($loc['compartment']['id'] ?? null) === $compartmentId) {
                $foundAssigned = true;
                $this->assertSame(7, (int) ($loc['quantity'] ?? -1));
            }
            if (($loc['compartment'] ?? null) === null) {
                $foundUnassigned = true;
                $this->assertSame(2, (int) ($loc['quantity'] ?? -1));
            }
        }
        $this->assertTrue($foundAssigned);
        $this->assertTrue($foundUnassigned);

        $list = $this->request('GET', '/api/v1/inventory', null, $this->authHeaderFor('staff@clinic.local'));
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
