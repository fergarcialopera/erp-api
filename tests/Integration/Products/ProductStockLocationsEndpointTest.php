<?php

declare(strict_types=1);

namespace Tests\Integration\Products;

use Tests\Integration\Support\BaseApiTestCase;

final class ProductStockLocationsEndpointTest extends BaseApiTestCase
{
    public function testGetStockLocationsWithoutTokenReturns401(): void
    {
        $res = $this->request('GET', '/api/v1/products/10000000-0000-4000-8000-000000000001/stock-locations');
        $this->assertSame(401, $res['status']);
    }

    public function testGetStockLocationsUnknownProductReturns404(): void
    {
        $res = $this->request(
            'GET',
            '/api/v1/products/99999999-9999-4999-8999-999999999999/stock-locations',
            null,
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(404, $res['status']);
    }

    public function testGetStockLocationsIsolatedByClinic(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'Producto otro tenant'],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $productId = (string) ($created['json']['data']['id'] ?? '');

        $otherClinic = $this->request(
            'GET',
            '/api/v1/products/' . $productId . '/stock-locations',
            null,
            $this->authHeaderFor('staff2@clinic.local')
        );
        $this->assertSame(404, $otherClinic['status']);
    }

    public function testGetStockLocationsReturnsProductWithLocations(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'Producto ubicaciones'],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $productId = (string) ($created['json']['data']['id'] ?? '');
        $sku = (string) ($created['json']['data']['sku'] ?? '');

        $clinicId = '11111111-1111-1111-1111-111111111111';

        $pdo = self::testPdo();
        $lockerStmt = $pdo->prepare(
            'INSERT INTO lockers (clinic_id, name, location, device_id, is_active, created_at, updated_at)
             VALUES (:clinic_id, :name, :location, :device_id, TRUE, NOW(), NOW())
             RETURNING id::text AS id'
        );
        $lockerStmt->execute([
            'clinic_id' => $clinicId,
            'name' => 'Locker Stock Loc',
            'location' => 'Test',
            'device_id' => 'DEV-' . bin2hex(random_bytes(3)),
        ]);
        $locker = $lockerStmt->fetch();
        $this->assertIsArray($locker);
        $lockerId = (string) ($locker['id'] ?? '');

        $compStmt = $pdo->prepare(
            'INSERT INTO compartments (clinic_id, locker_id, code, is_active, created_at, updated_at)
             VALUES (:clinic_id, :locker_id, :code, TRUE, NOW(), NOW())
             RETURNING id::text AS id, code'
        );
        $compStmt->execute([
            'clinic_id' => $clinicId,
            'locker_id' => $lockerId,
            'code' => 'SL-C1',
        ]);
        $comp = $compStmt->fetch();
        $this->assertIsArray($comp);
        $compartmentId = (string) ($comp['id'] ?? '');

        $pdo->prepare(
            'INSERT INTO inventory_items (clinic_id, product_id, compartment_id, quantity, updated_at)
             VALUES (:clinic_id, :product_id, :compartment_id, 7, NOW())'
        )->execute([
            'clinic_id' => $clinicId,
            'product_id' => $productId,
            'compartment_id' => $compartmentId,
        ]);

        $this->request(
            'POST',
            '/api/v1/entry-logs',
            ['sku' => $sku, 'name' => 'Producto ubicaciones', 'quantity' => 3],
            $this->authHeaderFor('admin@clinic.local')
        );

        $res = $this->request(
            'GET',
            '/api/v1/products/' . $productId . '/stock-locations',
            null,
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(200, $res['status']);

        $data = $res['json']['data'] ?? [];
        $this->assertIsArray($data);
        $this->assertSame($productId, (string) ($data['product']['id'] ?? ''));
        $this->assertSame($sku, (string) ($data['product']['sku'] ?? ''));
        $this->assertSame('Producto ubicaciones', (string) ($data['product']['name'] ?? ''));
        $this->assertSame(10, (int) ($data['quantity_total'] ?? -1));

        $locations = $data['locations'] ?? [];
        $this->assertIsArray($locations);
        $this->assertCount(2, $locations);

        $foundAssigned = false;
        $foundUnassigned = false;
        foreach ($locations as $loc) {
            if (($loc['compartment'] ?? null) === null) {
                $foundUnassigned = true;
                $this->assertSame(3, (int) ($loc['quantity'] ?? 0));
                $this->assertNull($loc['locker'] ?? null);
                continue;
            }

            if ((string) ($loc['compartment']['id'] ?? '') === $compartmentId) {
                $foundAssigned = true;
                $this->assertSame(7, (int) ($loc['quantity'] ?? 0));
                $this->assertSame('SL-C1', (string) ($loc['compartment']['code'] ?? ''));
                $this->assertSame($lockerId, (string) ($loc['locker']['id'] ?? ''));
            }
        }

        $this->assertTrue($foundAssigned);
        $this->assertTrue($foundUnassigned);
    }

    public function testGetStockLocationsEmptyWhenNoInventory(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'Sin stock'],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $productId = (string) ($created['json']['data']['id'] ?? '');

        $res = $this->request(
            'GET',
            '/api/v1/products/' . $productId . '/stock-locations',
            null,
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(200, $res['status']);

        $data = $res['json']['data'] ?? [];
        $this->assertSame(0, (int) ($data['quantity_total'] ?? -1));
        $this->assertSame([], $data['locations'] ?? null);
    }
}
