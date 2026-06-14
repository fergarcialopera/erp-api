<?php

declare(strict_types=1);

namespace Tests\Integration\Products;

use Tests\Integration\Support\BaseApiTestCase;

final class ProductsEndpointTest extends BaseApiTestCase
{
    public function testListProductsRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/products');
        $this->assertSame(401, $res['status']);
    }

    public function testCreateProductRequiresSuperAdmin(): void
    {
        $payload = ['name' => 'Producto-' . bin2hex(random_bytes(3))];

        $staff = $this->request('POST', '/api/v1/products', $payload, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/products', $payload, $this->authHeaderFor('tech@clinic-erp.com'));
        $this->assertSame(403, $tech['status']);

        $admin = $this->request('POST', '/api/v1/products', $payload, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $admin['status']);

        $super = $this->request('POST', '/api/v1/products', $payload, $this->authHeaderForSuperAdmin());
        $this->assertSame(201, $super['status']);
        $this->assertIsArray($super['json']);
        $this->assertArrayHasKey('data', $super['json']);
        $this->assertNotEmpty($super['json']['data']['id'] ?? null);
    }

    public function testCreateProductValidation(): void
    {
        $res = $this->request('POST', '/api/v1/products', ['name' => ''], $this->authHeaderForSuperAdmin());
        $this->assertSame(422, $res['status']);
    }

    public function testGetProductNotFound(): void
    {
        $res = $this->request('GET', '/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(404, $res['status']);
    }

    public function testProductHiddenUntilAdminEnablesVisibility(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'Hidden-' . bin2hex(random_bytes(3))],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $staffGet = $this->request('GET', '/api/v1/products/' . $id, null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(404, $staffGet['status']);

        $visible = $this->request(
            'PATCH',
            '/api/v1/clinic/products/' . $id,
            ['visible' => true],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(200, $visible['status']);

        $staffGetVisible = $this->request('GET', '/api/v1/products/' . $id, null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $staffGetVisible['status']);
    }

    public function testActiveFilter(): void
    {
        $p1 = $this->request('POST', '/api/v1/products', ['name' => 'Active-' . bin2hex(random_bytes(2))], $this->authHeaderForSuperAdmin());
        $p2 = $this->request('POST', '/api/v1/products', ['name' => 'Inactive-' . bin2hex(random_bytes(2))], $this->authHeaderForSuperAdmin());
        $this->assertSame(201, $p1['status']);
        $this->assertSame(201, $p2['status']);

        foreach ([$p1, $p2] as $productResponse) {
            $productId = (string) ($productResponse['json']['data']['id'] ?? '');
            $this->request(
                'PATCH',
                '/api/v1/clinic/products/' . $productId,
                ['visible' => true],
                $this->authHeaderFor('admin@clinic-erp.com')
            );
        }

        $inactiveId = (string) ($p2['json']['data']['id'] ?? '');
        $this->assertNotSame('', $inactiveId);
        $deleted = $this->request('DELETE', '/api/v1/products/' . $inactiveId, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $deleted['status']);

        $activeOnly = $this->request('GET', '/api/v1/products?active=true', null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(200, $activeOnly['status']);

        $inactiveOnly = $this->request('GET', '/api/v1/products?active=false', null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(200, $inactiveOnly['status']);
    }

    public function testPatchAndDeleteAuthorization(): void
    {
        $created = $this->request('POST', '/api/v1/products', ['name' => 'ToUpdate-' . bin2hex(random_bytes(2))], $this->authHeaderForSuperAdmin());
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $patchStaff = $this->request('PATCH', '/api/v1/products/' . $id, ['name' => 'Nope'], $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $patchStaff['status']);

        $patchAdmin = $this->request('PATCH', '/api/v1/products/' . $id, ['name' => 'Updated'], $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $patchAdmin['status']);

        $patchSuper = $this->request('PATCH', '/api/v1/products/' . $id, ['name' => 'Updated'], $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $patchSuper['status']);

        $deleteAdmin = $this->request('DELETE', '/api/v1/products/' . $id, null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $deleteAdmin['status']);

        $deleteSuper = $this->request('DELETE', '/api/v1/products/' . $id, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $deleteSuper['status']);
    }

    public function testPatchProductCanSetIsActiveFalse(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'BoolPatch-' . bin2hex(random_bytes(2)), 'is_active' => true],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $patched = $this->request(
            'PATCH',
            '/api/v1/products/' . $id,
            ['is_active' => false],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(200, $patched['status']);
        $this->assertIsArray($patched['json']);
        $this->assertArrayHasKey('data', $patched['json']);
        $this->assertFalse((bool) ($patched['json']['data']['is_active'] ?? true));
    }

    public function testSuperAdminCanToggleProductVisibilityInAnyClinic(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'SuperVis-' . bin2hex(random_bytes(2))],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(201, $created['status']);
        $productId = (string) ($created['json']['data']['id'] ?? '');
        $clinicB = '99999999-9999-9999-9999-999999999999';

        $visible = $this->request(
            'PATCH',
            '/api/v1/clinics/' . $clinicB . '/products/' . $productId,
            ['visible' => true],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(200, $visible['status']);
        $this->assertTrue((bool) ($visible['json']['data']['visible'] ?? false));
    }
}
