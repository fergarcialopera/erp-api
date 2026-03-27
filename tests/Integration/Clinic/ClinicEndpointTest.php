<?php

declare(strict_types=1);

namespace Tests\Integration\Clinic;

use Tests\Integration\Support\BaseApiTestCase;

final class ClinicEndpointTest extends BaseApiTestCase
{
    public function testGetClinicRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/clinic');
        $this->assertSame(401, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame(401, $res['json']['status'] ?? null);
    }

    public function testGetClinicReturnsClinicForAuthenticatedUser(): void
    {
        $res = $this->request('GET', '/api/v1/clinic', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertArrayHasKey('data', $res['json']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $res['json']['data']['id'] ?? null);
    }
}

