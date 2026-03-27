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

    public function testCreateProductRequiresTechnicianOrAdmin(): void
    {
        $payload = ['name' => 'Producto-' . bin2hex(random_bytes(3))];

        $staff = $this->request('POST', '/api/v1/products', $payload, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/products', $payload, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $tech['status']);
        $this->assertIsArray($tech['json']);
        $this->assertArrayHasKey('data', $tech['json']);
        $this->assertNotEmpty($tech['json']['data']['id'] ?? null);
    }

    public function testCreateProductValidation(): void
    {
        $res = $this->request('POST', '/api/v1/products', ['name' => ''], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(422, $res['status']);
    }

    public function testGetProductNotFound(): void
    {
        $res = $this->request('GET', '/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(404, $res['status']);
    }

    public function testProductIsIsolatedByClinicReturns404(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/products',
            ['name' => 'TenantA-' . bin2hex(random_bytes(3))],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $otherClinicGet = $this->request('GET', '/api/v1/products/' . $id, null, $this->authHeaderFor('admin2@clinic.local'));
        $this->assertSame(404, $otherClinicGet['status']);
    }

    public function testActiveFilter(): void
    {
        $p1 = $this->request('POST', '/api/v1/products', ['name' => 'Active-' . bin2hex(random_bytes(2))], $this->authHeaderFor('tech@clinic.local'));
        $p2 = $this->request('POST', '/api/v1/products', ['name' => 'Inactive-' . bin2hex(random_bytes(2))], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $p1['status']);
        $this->assertSame(201, $p2['status']);

        $inactiveId = (string) ($p2['json']['data']['id'] ?? '');
        $this->assertNotSame('', $inactiveId);
        $deleted = $this->request('DELETE', '/api/v1/products/' . $inactiveId, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $deleted['status']);

        $activeOnly = $this->request('GET', '/api/v1/products?active=true', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $activeOnly['status']);

        $inactiveOnly = $this->request('GET', '/api/v1/products?active=false', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $inactiveOnly['status']);
    }

    public function testPatchAndDeleteAuthorization(): void
    {
        $created = $this->request('POST', '/api/v1/products', ['name' => 'ToUpdate-' . bin2hex(random_bytes(2))], $this->authHeaderFor('admin@clinic.local'));
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $patchStaff = $this->request('PATCH', '/api/v1/products/' . $id, ['name' => 'Nope'], $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $patchStaff['status']);

        $patchTech = $this->request('PATCH', '/api/v1/products/' . $id, ['name' => 'Updated'], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(200, $patchTech['status']);

        $deleteTech = $this->request('DELETE', '/api/v1/products/' . $id, null, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(403, $deleteTech['status']);

        $deleteAdmin = $this->request('DELETE', '/api/v1/products/' . $id, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $deleteAdmin['status']);
    }
}

