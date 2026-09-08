<?php

declare(strict_types=1);

namespace Tests\Integration\Clinic;

use Symfony\Component\Uid\Uuid;
use Tests\Integration\Support\BaseApiTestCase;

final class ClinicPlatformEditEndpointTest extends BaseApiTestCase
{
    private const CLINIC_A = '11111111-1111-1111-1111-111111111111';
    private const CLINIC_B = '99999999-9999-9999-9999-999999999999';
    private const STAFF_A = '44444444-4444-4444-4444-444444444444';
    private const STAFF_B = '77777777-7777-7777-7777-777777777777';
    private const SUPER_ADMIN = '88888888-8888-8888-8888-888888888888';

    public function testGetClinicByIdRequiresSuperAdmin(): void
    {
        $staff = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_A, null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);

        $admin = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_A, null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(403, $admin['status']);

        $super = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_A, null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $super['status']);
        $this->assertSame(self::CLINIC_A, $super['json']['data']['id'] ?? null);
        $this->assertArrayHasKey('visible', $super['json']['data']);
    }

    public function testCreateClinicAcceptsVisible(): void
    {
        $created = $this->request('POST', '/api/v1/clinics', [
            'name' => 'Hidden Kiosk ' . bin2hex(random_bytes(2)),
            'password' => 'secret12',
            'visible' => false,
        ], $this->authHeaderForSuperAdmin());

        $this->assertSame(201, $created['status']);
        $this->assertFalse((bool) ($created['json']['data']['visible'] ?? true));
    }

    public function testListClinicUsersAndFilters(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $list = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_A . '/users', null, $super);
        $this->assertSame(200, $list['status']);
        $this->assertIsArray($list['json']['data']);

        $ids = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $list['json']['data']);
        $this->assertContains(self::STAFF_A, $ids);
        $this->assertNotContains(self::STAFF_B, $ids);
        $this->assertNotContains(self::SUPER_ADMIN, $ids);

        $search = $this->request(
            'GET',
            '/api/v1/clinics/' . self::CLINIC_A . '/users?search=staff@clinic-erp.com',
            null,
            $super
        );
        $this->assertSame(200, $search['status']);
        $this->assertNotEmpty($search['json']['data']);
    }

    public function testAssignUserHappyPathAndDuplicateConflict(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $userId = $this->insertUnassignedStaff('avail+' . bin2hex(random_bytes(3)) . '@clinic-erp.com');

        $available = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_A . '/users/available', null, $super);
        $this->assertSame(200, $available['status']);
        $availableIds = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $available['json']['data']);
        $this->assertContains($userId, $availableIds);

        $assigned = $this->request(
            'POST',
            '/api/v1/clinics/' . self::CLINIC_A . '/users',
            ['user_id' => $userId],
            $super
        );
        $this->assertSame(201, $assigned['status']);
        $this->assertSame(self::CLINIC_A, $assigned['json']['data']['clinic_id'] ?? null);

        $dup = $this->request(
            'POST',
            '/api/v1/clinics/' . self::CLINIC_A . '/users',
            ['user_id' => $userId],
            $super
        );
        $this->assertSame(409, $dup['status']);
    }

    public function testAssignUserFromAnotherClinicIsRejected(): void
    {
        $res = $this->request(
            'POST',
            '/api/v1/clinics/' . self::CLINIC_A . '/users',
            ['user_id' => self::STAFF_B],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(422, $res['status']);
    }

    public function testAssignSuperAdminIsRejected(): void
    {
        $res = $this->request(
            'POST',
            '/api/v1/clinics/' . self::CLINIC_A . '/users',
            ['user_id' => self::SUPER_ADMIN],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(422, $res['status']);
    }

    public function testAssignRequiresSuperAdmin(): void
    {
        $userId = $this->insertUnassignedStaff('forbid+' . bin2hex(random_bytes(3)) . '@clinic-erp.com');
        $res = $this->request(
            'POST',
            '/api/v1/clinics/' . self::CLINIC_A . '/users',
            ['user_id' => $userId],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(403, $res['status']);
    }

    public function testDisableAndEnableClinicAccessKeepsAssignment(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $disabled = $this->request(
            'PATCH',
            '/api/v1/clinics/' . self::CLINIC_A . '/users/' . self::STAFF_A,
            ['is_active' => false],
            $super
        );
        $this->assertSame(200, $disabled['status']);
        $this->assertFalse((bool) ($disabled['json']['data']['is_active'] ?? true));
        $this->assertSame(self::CLINIC_A, $disabled['json']['data']['clinic_id'] ?? null);

        $enabled = $this->request(
            'PATCH',
            '/api/v1/clinics/' . self::CLINIC_A . '/users/' . self::STAFF_A,
            ['is_active' => true],
            $super
        );
        $this->assertSame(200, $enabled['status']);
        $this->assertTrue((bool) ($enabled['json']['data']['is_active'] ?? false));
    }

    public function testPatchAccessForUserOfAnotherClinicReturns404(): void
    {
        $res = $this->request(
            'PATCH',
            '/api/v1/clinics/' . self::CLINIC_A . '/users/' . self::STAFF_B,
            ['is_active' => false],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(404, $res['status']);
    }

    public function testListClinicProductsIncludesVisibleDefaultFalse(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $created = $this->request('POST', '/api/v1/products', [
            'name' => 'PlatformVis-' . bin2hex(random_bytes(3)),
        ], $super);
        $this->assertSame(201, $created['status']);
        $productId = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $productId);

        $list = $this->request('GET', '/api/v1/clinics/' . self::CLINIC_B . '/products', null, $super);
        $this->assertSame(200, $list['status']);
        $match = null;
        foreach ($list['json']['data'] as $row) {
            if (($row['id'] ?? '') === $productId) {
                $match = $row;
                break;
            }
        }
        $this->assertIsArray($match);
        $this->assertArrayHasKey('visible', $match);
        $this->assertFalse((bool) $match['visible']);

        $toggled = $this->request(
            'PATCH',
            '/api/v1/clinics/' . self::CLINIC_B . '/products/' . $productId,
            ['visible' => true],
            $super
        );
        $this->assertSame(200, $toggled['status']);
        $this->assertTrue((bool) ($toggled['json']['data']['visible'] ?? false));
    }

    public function testListClinicProductsForbiddenForStaff(): void
    {
        $res = $this->request(
            'GET',
            '/api/v1/clinics/' . self::CLINIC_A . '/products',
            null,
            $this->authHeaderFor('staff@clinic-erp.com')
        );
        $this->assertSame(403, $res['status']);
    }

    public function testListClinicAmbientes(): void
    {
        $ambienteId = $this->createAmbienteLinkedToClinicA('PlatAmb-' . bin2hex(random_bytes(2)));
        $list = $this->request(
            'GET',
            '/api/v1/clinics/' . self::CLINIC_A . '/ambientes',
            null,
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(200, $list['status']);
        $ids = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $list['json']['data']);
        $this->assertContains($ambienteId, $ids);
        $match = null;
        foreach ($list['json']['data'] as $row) {
            if (($row['id'] ?? '') === $ambienteId) {
                $match = $row;
                break;
            }
        }
        $this->assertIsArray($match);
        $this->assertArrayHasKey('visible', $match);
        $this->assertArrayHasKey('is_active', $match);
    }

    private function insertUnassignedStaff(string $email): string
    {
        $id = Uuid::v4()->toRfc4122();
        $hash = password_hash('secret12', PASSWORD_BCRYPT);
        self::upsertUser($id, null, $email, 'STAFF', $hash);
        self::testPdo()->prepare('UPDATE users SET name = :name WHERE id = :id')->execute([
            'name' => 'Unassigned ' . $email,
            'id' => $id,
        ]);

        return $id;
    }
}
