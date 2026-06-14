<?php

declare(strict_types=1);

namespace Tests\Integration\Zones;

use Tests\Integration\Support\BaseApiTestCase;

final class ZonesEndpointTest extends BaseApiTestCase
{
    private function createAmbienteId(): string
    {
        $created = $this->request('POST', '/api/v1/ambientes', ['name' => 'Ambiente-' . bin2hex(random_bytes(2))], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);
        return $id;
    }

    public function testListZonesRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/zones');
        $this->assertSame(401, $res['status']);
    }

    public function testCreateZoneValidationAndAuthorization(): void
    {
        $ambienteId = $this->createAmbienteId();

        $staff = $this->request('POST', '/api/v1/zones', ['ambiente_id' => $ambienteId, 'code' => 'C-01'], $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $invalid = $this->request('POST', '/api/v1/zones', ['ambiente_id' => '', 'code' => ''], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(422, $invalid['status']);

        $tech = $this->request('POST', '/api/v1/zones', ['ambiente_id' => $ambienteId, 'code' => 'C-01'], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $tech['status']);
    }

    public function testZoneGetPatchDeleteFlow(): void
    {
        $ambienteId = $this->createAmbienteId();
        $created = $this->request('POST', '/api/v1/zones', ['ambiente_id' => $ambienteId, 'code' => 'C-' . bin2hex(random_bytes(2))], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');

        $get = $this->request('GET', '/api/v1/zones/' . $id, null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $get['status']);

        $patch = $this->request('PATCH', '/api/v1/zones/' . $id, ['code' => 'C-upd'], $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(200, $patch['status']);

        $deleteTech = $this->request('DELETE', '/api/v1/zones/' . $id, null, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(403, $deleteTech['status']);
        $deleteAdmin = $this->request('DELETE', '/api/v1/zones/' . $id, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $deleteAdmin['status']);
    }

    public function testZoneIsolationByClinicReturns404(): void
    {
        $ambienteId = $this->createAmbienteId();
        $created = $this->request('POST', '/api/v1/zones', ['ambiente_id' => $ambienteId, 'code' => 'C-tenant'], $this->authHeaderFor('admin@clinic.local'));
        $id = (string) ($created['json']['data']['id'] ?? '');

        $other = $this->request('GET', '/api/v1/zones/' . $id, null, $this->authHeaderFor('admin2@clinic.local'));
        $this->assertSame(404, $other['status']);
    }
}

