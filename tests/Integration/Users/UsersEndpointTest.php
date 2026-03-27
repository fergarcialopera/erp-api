<?php

declare(strict_types=1);

namespace Tests\Integration\Users;

use Tests\Integration\Support\BaseApiTestCase;

final class UsersEndpointTest extends BaseApiTestCase
{
    public function testListUsersRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/users');
        $this->assertSame(401, $res['status']);
    }

    public function testListUsersRequiresAdmin(): void
    {
        $staff = $this->request('GET', '/api/v1/users', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('GET', '/api/v1/users', null, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(403, $tech['status']);

        $admin = $this->request('GET', '/api/v1/users', null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $admin['status']);
        $this->assertIsArray($admin['json']);
        $this->assertArrayHasKey('data', $admin['json']);
        $this->assertIsArray($admin['json']['data']);
    }

    public function testCreateUserValidation(): void
    {
        $res = $this->request('POST', '/api/v1/users', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => '123',
            'role' => 'NOPE',
        ], $this->authHeaderFor('admin@clinic.local'));

        $this->assertSame(422, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame(422, $res['json']['status'] ?? null);
    }

    public function testCreateGetPatchDeleteUserHappyPath(): void
    {
        $email = 'user+' . bin2hex(random_bytes(4)) . '@clinic.local';

        $created = $this->request('POST', '/api/v1/users', [
            'name' => 'User Test',
            'email' => $email,
            'password' => 'secret12',
            'role' => 'STAFF',
        ], $this->authHeaderFor('admin@clinic.local'));

        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $get = $this->request('GET', '/api/v1/users/' . $id, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $get['status']);
        $this->assertSame($email, $get['json']['data']['email'] ?? null);

        $patched = $this->request('PATCH', '/api/v1/users/' . $id, [
            'role' => 'TECHNICIAN',
            'is_active' => true,
            'password' => 'newpass12',
        ], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $patched['status']);
        $this->assertSame('TECHNICIAN', $patched['json']['data']['role'] ?? null);

        $deleted = $this->request('DELETE', '/api/v1/users/' . $id, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $deleted['status']);

        $getAfter = $this->request('GET', '/api/v1/users/' . $id, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $getAfter['status']);
        $this->assertFalse((bool) ($getAfter['json']['data']['is_active'] ?? true));
    }

    public function testUserIsIsolatedByClinicReturns404(): void
    {
        $email = 'user+' . bin2hex(random_bytes(4)) . '@clinic.local';

        $created = $this->request('POST', '/api/v1/users', [
            'name' => 'Other Clinic User',
            'email' => $email,
            'password' => 'secret12',
            'role' => 'STAFF',
        ], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');

        $otherClinicGet = $this->request('GET', '/api/v1/users/' . $id, null, $this->authHeaderFor('admin2@clinic.local'));
        $this->assertSame(404, $otherClinicGet['status']);
    }
}

