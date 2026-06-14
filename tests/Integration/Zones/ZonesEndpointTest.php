<?php

declare(strict_types=1);

namespace Tests\Integration\Zones;

use Tests\Integration\Support\BaseApiTestCase;

final class ZonesEndpointTest extends BaseApiTestCase
{
    public function testListZonesRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/zones');
        $this->assertSame(401, $res['status']);
    }

    public function testCreateZoneValidationAndAuthorization(): void
    {
        $ambienteId = $this->createAmbienteLinkedToClinicA();

        $staff = $this->request('POST', '/api/v1/zones', ['ambiente_id' => $ambienteId, 'code' => 'C-01'], $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);

        $invalid = $this->request('POST', '/api/v1/zones', ['ambiente_id' => '', 'code' => ''], $this->authHeaderForSuperAdmin());
        $this->assertSame(422, $invalid['status']);

        $super = $this->request('POST', '/api/v1/zones', ['ambiente_id' => $ambienteId, 'code' => 'C-01'], $this->authHeaderForSuperAdmin());
        $this->assertSame(201, $super['status']);
    }

    public function testZoneGetPatchDeleteFlow(): void
    {
        $ambienteId = $this->createAmbienteLinkedToClinicA();
        $created = $this->request(
            'POST',
            '/api/v1/zones',
            ['ambiente_id' => $ambienteId, 'code' => 'C-' . bin2hex(random_bytes(2))],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');

        $get = $this->request('GET', '/api/v1/zones/' . $id, null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $get['status']);

        $patchAdmin = $this->request('PATCH', '/api/v1/zones/' . $id, ['code' => 'C-upd'], $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $patchAdmin['status']);

        $patchSuper = $this->request('PATCH', '/api/v1/zones/' . $id, ['code' => 'C-upd'], $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $patchSuper['status']);

        $deleteAdmin = $this->request('DELETE', '/api/v1/zones/' . $id, null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $deleteAdmin['status']);

        $deleteSuper = $this->request('DELETE', '/api/v1/zones/' . $id, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $deleteSuper['status']);
    }

    public function testZoneIsolationByClinicReturns404(): void
    {
        $ambienteId = $this->createAmbienteLinkedToClinicA();
        $created = $this->request(
            'POST',
            '/api/v1/zones',
            ['ambiente_id' => $ambienteId, 'code' => 'C-tenant'],
            $this->authHeaderForSuperAdmin()
        );
        $id = (string) ($created['json']['data']['id'] ?? '');

        $other = $this->request('GET', '/api/v1/zones/' . $id, null, $this->authHeaderFor('admin2@clinic-erp.com'));
        $this->assertSame(404, $other['status']);
    }
}
