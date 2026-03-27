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

    public function testPostSettingsRequiresAdmin(): void
    {
        $payload = ['key' => 'k' . bin2hex(random_bytes(2)), 'value' => 'v'];

        $staff = $this->request('POST', '/api/v1/settings', $payload, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/settings', $payload, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(403, $tech['status']);

        $admin = $this->request('POST', '/api/v1/settings', $payload, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(201, $admin['status']);
        $this->assertArrayHasKey('data', $admin['json'] ?? []);
    }

    public function testPostSettingsValidation(): void
    {
        $res = $this->request('POST', '/api/v1/settings', ['key' => '', 'value' => ''], $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(422, $res['status']);
    }

    public function testSettingsAreIsolatedByClinic(): void
    {
        $key = 'tenant-key-' . bin2hex(random_bytes(3));
        $this->request('POST', '/api/v1/settings', ['key' => $key, 'value' => 'A'], $this->authHeaderFor('admin@clinic.local'));

        $listA = $this->request('GET', '/api/v1/settings', null, $this->authHeaderFor('staff@clinic.local'));
        $listB = $this->request('GET', '/api/v1/settings', null, $this->authHeaderFor('staff2@clinic.local'));

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
