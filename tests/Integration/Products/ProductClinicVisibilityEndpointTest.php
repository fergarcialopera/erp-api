<?php

declare(strict_types=1);

namespace Tests\Integration\Products;

use Tests\Integration\Support\BaseApiTestCase;

final class ProductClinicVisibilityEndpointTest extends BaseApiTestCase
{
    private const CLINIC_A = '11111111-1111-1111-1111-111111111111';
    private const CLINIC_B = '99999999-9999-9999-9999-999999999999';

    public function testCreateProductDefaultsNotVisibleInAnyClinic(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $created = $this->request('POST', '/api/v1/products', [
            'name' => 'OptIn-' . bin2hex(random_bytes(3)),
        ], $super);
        $this->assertSame(201, $created['status']);
        $productId = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $productId);

        $clinics = $this->request('GET', '/api/v1/products/' . $productId . '/clinics', null, $super);
        $this->assertSame(200, $clinics['status']);
        $this->assertNotEmpty($clinics['json']['data']);
        foreach ($clinics['json']['data'] as $row) {
            $this->assertFalse((bool) ($row['visible'] ?? true), 'Expected opt-in default false');
            $this->assertArrayHasKey('visible_in_kiosk', $row);
            $this->assertArrayHasKey('clinic_id', $row);
            $this->assertArrayHasKey('name', $row);
        }

        $clinicList = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_A . '/products', null, $super);
        $this->assertSame(200, $clinicList['status']);
        $match = $this->findById($clinicList['json']['data'], $productId);
        $this->assertIsArray($match);
        $this->assertFalse((bool) $match['visible']);
    }

    public function testBulkClinicActivateIsIdempotent(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $created = $this->request('POST', '/api/v1/products', [
            'name' => 'BulkClinic-' . bin2hex(random_bytes(3)),
        ], $super);
        $productId = (string) ($created['json']['data']['id'] ?? '');

        $first = $this->request(
            'PATCH',
            '/api/v1/clinics/' . self::CLINIC_A . '/products',
            ['visible' => true, 'only_active_catalog' => true],
            $super
        );
        $this->assertSame(200, $first['status']);
        $this->assertTrue((bool) ($first['json']['data']['visible'] ?? false));
        $this->assertGreaterThan(0, (int) ($first['json']['data']['matched'] ?? 0));
        $this->assertGreaterThan(0, (int) ($first['json']['data']['updated'] ?? 0));

        $second = $this->request(
            'PATCH',
            '/api/v1/clinics/' . self::CLINIC_A . '/products',
            ['visible' => true, 'only_active_catalog' => true],
            $super
        );
        $this->assertSame(200, $second['status']);
        $this->assertSame($first['json']['data']['matched'], $second['json']['data']['matched']);
        $this->assertSame(0, (int) ($second['json']['data']['updated'] ?? -1));
        $this->assertSame(
            (int) $second['json']['data']['matched'],
            (int) $second['json']['data']['unchanged']
        );

        $listed = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_A . '/products', null, $super);
        $match = $this->findById($listed['json']['data'], $productId);
        $this->assertIsArray($match);
        $this->assertTrue((bool) $match['visible']);
    }

    public function testListAndPatchFromProductAreEquivalentToClinicPatch(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $created = $this->request('POST', '/api/v1/products', [
            'name' => 'FromProduct-' . bin2hex(random_bytes(3)),
        ], $super);
        $productId = (string) ($created['json']['data']['id'] ?? '');

        $fromProduct = $this->request(
            'PATCH',
            '/api/v1/products/' . $productId . '/clinics/' . self::CLINIC_B,
            ['visible' => true],
            $super
        );
        $this->assertSame(200, $fromProduct['status']);
        $this->assertSame($productId, $fromProduct['json']['data']['product_id'] ?? null);
        $this->assertSame(self::CLINIC_B, $fromProduct['json']['data']['clinic_id'] ?? null);
        $this->assertTrue((bool) ($fromProduct['json']['data']['visible'] ?? false));

        $fromClinic = $this->request(
            'PATCH',
            '/api/v1/clinics/' . self::CLINIC_B . '/products/' . $productId,
            ['visible' => true],
            $super
        );
        $this->assertSame(200, $fromClinic['status']);
        $this->assertTrue((bool) ($fromClinic['json']['data']['visible'] ?? false));

        $list = $this->request(
            'GET',
            '/api/v1/products/' . $productId . '/clinics?visible=true',
            null,
            $super
        );
        $this->assertSame(200, $list['status']);
        $ids = array_map(static fn (array $row): string => (string) ($row['clinic_id'] ?? ''), $list['json']['data']);
        $this->assertContains(self::CLINIC_B, $ids);
    }

    public function testBulkFromProductAllClinicsAndSubset(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $created = $this->request('POST', '/api/v1/products', [
            'name' => 'BulkProduct-' . bin2hex(random_bytes(3)),
        ], $super);
        $productId = (string) ($created['json']['data']['id'] ?? '');

        $all = $this->request(
            'PATCH',
            '/api/v1/products/' . $productId . '/clinics',
            ['visible' => true],
            $super
        );
        $this->assertSame(200, $all['status']);
        $this->assertGreaterThanOrEqual(2, (int) ($all['json']['data']['matched'] ?? 0));
        $this->assertGreaterThan(0, (int) ($all['json']['data']['updated'] ?? 0));

        $again = $this->request(
            'PATCH',
            '/api/v1/products/' . $productId . '/clinics',
            ['visible' => true, 'clinic_ids' => [self::CLINIC_A]],
            $super
        );
        $this->assertSame(200, $again['status']);
        $this->assertSame(1, (int) ($again['json']['data']['matched'] ?? 0));
        $this->assertSame(0, (int) ($again['json']['data']['updated'] ?? -1));

        $unknown = $this->request(
            'PATCH',
            '/api/v1/products/' . $productId . '/clinics',
            ['visible' => false, 'clinic_ids' => ['00000000-0000-4000-8000-000000000000']],
            $super
        );
        $this->assertSame(422, $unknown['status']);
    }

    public function testProductClinicsNotFoundAndForbidden(): void
    {
        $missing = $this->request(
            'GET',
            '/api/v1/products/00000000-0000-4000-8000-000000000000/clinics',
            null,
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(404, $missing['status']);

        $staff = $this->request(
            'GET',
            '/api/v1/products/00000000-0000-4000-8000-000000000000/clinics',
            null,
            $this->authHeaderFor('staff@clinic-erp.com')
        );
        $this->assertSame(403, $staff['status']);

        $clinicMissing = $this->request(
            'PATCH',
            '/api/v1/clinics/00000000-0000-4000-8000-000000000000/products',
            ['visible' => true],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(404, $clinicMissing['status']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findById(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }
}
