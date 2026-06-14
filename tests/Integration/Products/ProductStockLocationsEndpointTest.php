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
            $this->authHeaderFor('staff@clinic-erp.com')
        );
        $this->assertSame(404, $res['status']);
    }

    public function testGetStockLocationsIsolatedByClinic(): void
    {
        $product = $this->createProductVisibleInClinicA('Producto otro tenant');
        $productId = $product['id'];

        $otherClinic = $this->request(
            'GET',
            '/api/v1/products/' . $productId . '/stock-locations',
            null,
            $this->authHeaderFor('staff2@clinic-erp.com')
        );
        $this->assertSame(404, $otherClinic['status']);
    }

    public function testGetStockLocationsReturnsProductWithLocations(): void
    {
        $product = $this->createProductVisibleInClinicA('Producto ubicaciones');
        $productId = $product['id'];
        $sku = $product['sku'];

        $clinicId = '11111111-1111-1111-1111-111111111111';
        $location = $this->insertAmbienteAndZoneForClinic($clinicId, 'Ambiente Stock Loc', 'SL-C1');
        $ambienteId = $location['ambiente_id'];
        $zoneId = $location['zone_id'];

        self::testPdo()->prepare(
            'INSERT INTO inventory_items (clinic_id, product_id, zone_id, quantity, updated_at)
             VALUES (:clinic_id, :product_id, :zone_id, 7, NOW())'
        )->execute([
            'clinic_id' => $clinicId,
            'product_id' => $productId,
            'zone_id' => $zoneId,
        ]);

        $res = $this->request(
            'GET',
            '/api/v1/products/' . $productId . '/stock-locations',
            null,
            $this->authHeaderFor('staff@clinic-erp.com')
        );
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'] ?? [];
        $this->assertSame($productId, (string) ($data['product']['id'] ?? ''));
        $this->assertSame($sku, (string) ($data['product']['sku'] ?? ''));
        $this->assertSame(7, (int) ($data['quantity_total'] ?? -1));

        $locations = $data['locations'] ?? [];
        $this->assertIsArray($locations);
        $this->assertNotEmpty($locations);

        $found = false;
        foreach ($locations as $loc) {
            if ((string) ($loc['zone']['id'] ?? '') === $zoneId) {
                $found = true;
                $this->assertSame('SL-C1', (string) ($loc['zone']['code'] ?? ''));
                $this->assertSame($ambienteId, (string) ($loc['ambiente']['id'] ?? ''));
            }
        }
        $this->assertTrue($found);
    }
}
