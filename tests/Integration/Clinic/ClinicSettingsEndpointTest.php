<?php

declare(strict_types=1);

namespace Tests\Integration\Clinic;

use Tests\Integration\Support\BaseApiTestCase;

final class ClinicSettingsEndpointTest extends BaseApiTestCase
{
    public function testPatchClinicSettingsRequiresAuth(): void
    {
        $res = $this->request('PATCH', '/api/v1/clinic/settings', ['open_latency_ms' => 10]);
        $this->assertSame(401, $res['status']);
    }

    public function testPatchClinicSettingsRequiresAdmin(): void
    {
        $staff = $this->request(
            'PATCH',
            '/api/v1/clinic/settings',
            ['open_latency_ms' => 10],
            $this->authHeaderFor('staff@clinic-erp.com')
        );
        $this->assertSame(403, $staff['status']);

        $admin = $this->request(
            'PATCH',
            '/api/v1/clinic/settings',
            ['open_latency_ms' => 10],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(200, $admin['status']);
        $this->assertIsArray($admin['json']);
        $this->assertArrayHasKey('data', $admin['json']);
    }

    public function testPatchClinicSettingsValidation(): void
    {
        $res = $this->request(
            'PATCH',
            '/api/v1/clinic/settings',
            ['open_latency_ms' => -1],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(422, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame(422, $res['json']['status'] ?? null);
    }
}

