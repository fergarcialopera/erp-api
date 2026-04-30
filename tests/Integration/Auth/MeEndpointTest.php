<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use Tests\Integration\Support\BaseApiTestCase;

final class MeEndpointTest extends BaseApiTestCase
{
    public function testMeWithoutTokenReturns401(): void
    {
        $res = $this->request('GET', '/api/v1/me');
        $this->assertSame(401, $res['status']);
    }

    public function testMeWithInvalidTokenReturns401(): void
    {
        $res = $this->request('GET', '/api/v1/me', null, ['Authorization' => 'Bearer invalid-token']);
        $this->assertSame(401, $res['status']);
    }

    public function testMeWithValidTokenReturnsUserContext(): void
    {
        $res = $this->request('GET', '/api/v1/me', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertArrayHasKey('data', $res['json']);
        $this->assertIsArray($res['json']['data']);
        $this->assertSame('44444444-4444-4444-4444-444444444444', $res['json']['data']['id'] ?? null);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $res['json']['data']['clinic_id'] ?? null);
        $this->assertSame('STAFF', $res['json']['data']['role'] ?? null);
        $this->assertSame('staff@clinic.local', $res['json']['data']['email'] ?? null);
    }
}
