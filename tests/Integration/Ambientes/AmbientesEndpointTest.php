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
        $staff = $this->request('POST', '/api/v1/ambientes', $payload, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);
        $tech = $this->request('POST', '/api/v1/ambientes', $payload, $this->authHeaderFor('tech@clinic-erp.com'));
        $this->assertSame(403, $tech['status']);

        $super = $this->request('POST', '/api/v1/ambientes', $payload, $this->authHeaderForSuperAdmin());
        $this->assertSame(201, $super['status']);
    }

    public function testGetPatchDeleteAmbienteFlow(): void
    {
        $id = $this->createAmbienteLinkedToClinicA('L-' . bin2hex(random_bytes(2)));

        $get = $this->request('GET', '/api/v1/ambientes/' . $id, null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $get['status']);

        $patchAdmin = $this->request('PATCH', '/api/v1/ambientes/' . $id, ['location' => 'A1'], $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $patchAdmin['status']);

        $patchSuper = $this->request('PATCH', '/api/v1/ambientes/' . $id, ['location' => 'A1'], $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $patchSuper['status']);

        $deleteAdmin = $this->request('DELETE', '/api/v1/ambientes/' . $id, null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $deleteAdmin['status']);

        $deleteSuper = $this->request('DELETE', '/api/v1/ambientes/' . $id, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $deleteSuper['status']);
    }

    public function testAmbienteIsolationByClinicReturns404(): void
    {
        $id = $this->createAmbienteLinkedToClinicA('TenantA-' . bin2hex(random_bytes(2)));
        $other = $this->request('GET', '/api/v1/ambientes/' . $id, null, $this->authHeaderFor('admin2@clinic-erp.com'));
        $this->assertSame(404, $other['status']);
    }

    public function testSuperAdminCanToggleAmbienteVisibilityInAnyClinic(): void
    {
        $ambienteId = $this->createAmbienteLinkedToClinicA('Vis-' . bin2hex(random_bytes(2)));
        $clinicB = '99999999-9999-9999-9999-999999999999';

        $linked = $this->request(
            'POST',
            '/api/v1/clinics/' . $clinicB . '/ambientes',
            ['ambiente_id' => $ambienteId],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(201, $linked['status']);

        $visible = $this->request(
            'PATCH',
            '/api/v1/clinics/' . $clinicB . '/ambientes/' . $ambienteId,
            ['visible' => true],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(200, $visible['status']);
        $this->assertTrue((bool) ($visible['json']['data']['visible'] ?? false));
    }
}
