<?php

declare(strict_types=1);

namespace Tests\Integration\Users;

use Tests\Integration\Support\BaseApiTestCase;

final class UsersEndpointTest extends BaseApiTestCase
{
    private const CLINIC_A = '11111111-1111-1111-1111-111111111111';

    public function testListUsersRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/users');
        $this->assertSame(401, $res['status']);
    }

    public function testListUsersRequiresSuperAdmin(): void
    {
        $staff = $this->request('GET', '/api/v1/users', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('GET', '/api/v1/users', null, $this->authHeaderFor('tech@clinic-erp.com'));
        $this->assertSame(403, $tech['status']);

        $admin = $this->request('GET', '/api/v1/users', null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $admin['status']);

        $super = $this->request('GET', '/api/v1/users', null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $super['status']);
        $this->assertIsArray($super['json']);
        $this->assertArrayHasKey('data', $super['json']);
        $this->assertIsArray($super['json']['data']);
    }

    public function testCreateUserValidation(): void
    {
        $res = $this->request('POST', '/api/v1/users', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => '123',
            'role' => 'NOPE',
        ], $this->authHeaderForSuperAdmin());

        $this->assertSame(422, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame(422, $res['json']['status'] ?? null);
    }

    public function testCreateGetPatchDeleteUserHappyPath(): void
    {
        $email = 'user+' . bin2hex(random_bytes(4)) . '@clinic-erp.com';

        $created = $this->request('POST', '/api/v1/users', [
            'name' => 'User Test',
            'email' => $email,
            'password' => 'secret12',
            'role' => 'STAFF',
            'clinic_id' => self::CLINIC_A,
        ], $this->authHeaderForSuperAdmin());

        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $get = $this->request('GET', '/api/v1/users/' . $id, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $get['status']);
        $this->assertSame($email, $get['json']['data']['email'] ?? null);

        $patched = $this->request('PATCH', '/api/v1/users/' . $id, [
            'role' => 'TECHNICIAN',
            'is_active' => true,
            'password' => 'newpass12',
        ], $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $patched['status']);
        $this->assertSame('TECHNICIAN', $patched['json']['data']['role'] ?? null);

        $deleted = $this->request('DELETE', '/api/v1/users/' . $id, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $deleted['status']);

        $getAfter = $this->request('GET', '/api/v1/users/' . $id, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $getAfter['status']);
        $this->assertFalse((bool) ($getAfter['json']['data']['is_active'] ?? true));
    }

    public function testSuperAdminCanAssignAdminToMultipleClinics(): void
    {
        $email = 'multi-admin+' . bin2hex(random_bytes(4)) . '@clinic-erp.com';

        $created = $this->request('POST', '/api/v1/users', [
            'name' => 'Multi Clinic Admin',
            'email' => $email,
            'password' => 'secret12',
            'role' => 'ADMIN',
            'clinic_ids' => [
                self::CLINIC_A,
                '99999999-9999-9999-9999-999999999999',
            ],
        ], $this->authHeaderForSuperAdmin());

        $this->assertSame(201, $created['status']);
        $clinicIds = $created['json']['data']['clinic_ids'] ?? [];
        $this->assertCount(2, $clinicIds);
    }
}
