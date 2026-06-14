<?php

declare(strict_types=1);

namespace Tests\Integration\Settings;

use Tests\Integration\Support\BaseApiTestCase;

final class SettingsEndpointTest extends BaseApiTestCase
{
    public function testGetSettingsWithoutTokenReturns401(): void
    {
        $res = $this->request('GET', '/api/v1/settings');
        $this->assertSame(401, $res['status']);
    }

    public function testGetSettingsRequiresAdmin(): void
    {
        $staff = $this->request('GET', '/api/v1/settings', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('GET', '/api/v1/settings', null, $this->authHeaderFor('tech@clinic-erp.com'));
        $this->assertSame(403, $tech['status']);

        $admin = $this->request('GET', '/api/v1/settings', null, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(200, $admin['status']);
    }

    public function testPostSettingsRequiresAdmin(): void
    {
        $payload = ['key' => 'k' . bin2hex(random_bytes(2)), 'value' => 'v'];

        $staff = $this->request('POST', '/api/v1/settings', $payload, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/settings', $payload, $this->authHeaderFor('tech@clinic-erp.com'));
        $this->assertSame(403, $tech['status']);

        $admin = $this->request('POST', '/api/v1/settings', $payload, $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(201, $admin['status']);
        $this->assertArrayHasKey('data', $admin['json'] ?? []);
    }

    public function testPostSettingsValidation(): void
    {
        $res = $this->request('POST', '/api/v1/settings', ['key' => '', 'value' => ''], $this->authHeaderFor('admin@clinic-erp.com'));
        $this->assertSame(422, $res['status']);
    }

    public function testSettingsAreIsolatedByClinic(): void
    {
        $key = 'tenant-key-' . bin2hex(random_bytes(3));
        $this->request('POST', '/api/v1/settings', ['key' => $key, 'value' => 'A'], $this->authHeaderFor('admin@clinic-erp.com'));

        $listA = $this->request('GET', '/api/v1/settings', null, $this->authHeaderFor('admin@clinic-erp.com'));
        $listB = $this->request('GET', '/api/v1/settings', null, $this->authHeaderFor('staff2@clinic-erp.com'));

        $hasInA = false;
        $hasInB = false;
        foreach (($listA['json']['data'] ?? []) as $row) {
            if (($row['key'] ?? '') === $key) {
                $hasInA = true;
            }
        }
        foreach (($listB['json']['data'] ?? []) as $row) {
            if (($row['key'] ?? '') === $key) {
                $hasInB = true;
            }
        }

        $this->assertTrue($hasInA);
        $this->assertFalse($hasInB);
    }
}
