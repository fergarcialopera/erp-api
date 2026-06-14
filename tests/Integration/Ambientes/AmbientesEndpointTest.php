<?php

declare(strict_types=1);

namespace Tests\Integration\Ambientes;

use Tests\Integration\Support\BaseApiTestCase;

final class AmbientesEndpointTest extends BaseApiTestCase
{
    public function testListAmbientesRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/ambientes');
        $this->assertSame(401, $res['status']);
    }

    public function testCreateAmbienteAuthorization(): void
    {
        $payload = ['name' => 'Ambiente-' . bin2hex(random_bytes(2))];
        $staff = $this->request('POST', '/api/v1/ambientes', $payload, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);
        $tech = $this->request('POST', '/api/v1/ambientes', $payload, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $tech['status']);
    }

    public function testGetPatchDeleteAmbienteFlow(): void
    {
        $created = $this->request('POST', '/api/v1/ambientes', ['name' => 'L-' . bin2hex(random_bytes(2))], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $get = $this->request('GET', '/api/v1/ambientes/' . $id, null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $get['status']);

        $patch = $this->request('PATCH', '/api/v1/ambientes/' . $id, ['location' => 'A1'], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(200, $patch['status']);

        $deleteTech = $this->request('DELETE', '/api/v1/ambientes/' . $id, null, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(403, $deleteTech['status']);
        $deleteAdmin = $this->request('DELETE', '/api/v1/ambientes/' . $id, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $deleteAdmin['status']);
    }

    public function testAmbienteIsolationByClinicReturns404(): void
    {
        $created = $this->request('POST', '/api/v1/ambientes', ['name' => 'TenantA-' . bin2hex(random_bytes(2))], $this->authHeaderFor('admin@clinic.local'));
        $id = (string) ($created['json']['data']['id'] ?? '');
        $other = $this->request('GET', '/api/v1/ambientes/' . $id, null, $this->authHeaderFor('admin2@clinic.local'));
        $this->assertSame(404, $other['status']);
    }
}

