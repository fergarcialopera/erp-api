<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use Tests\Integration\Support\BaseApiTestCase;

final class LogoutEndpointTest extends BaseApiTestCase
{
    public function testLogoutWithoutTokenReturns401(): void
    {
        $res = $this->request('POST', '/api/v1/auth/logout');
        $this->assertSame(401, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame(401, $res['json']['status'] ?? null);
    }

    public function testLogoutInvalidatesToken(): void
    {
        $login = $this->login('staff@clinic.local');
        $this->assertSame(200, $login['status']);
        $token = (string) ($login['json']['data']['access_token'] ?? '');
        $this->assertNotSame('', $token);

        $logout = $this->request('POST', '/api/v1/auth/logout', null, ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(200, $logout['status']);
        $this->assertIsArray($logout['json']);
        $this->assertArrayHasKey('data', $logout['json']);
        $this->assertTrue((bool) ($logout['json']['data']['logged_out'] ?? false));

        $me = $this->request('GET', '/api/v1/me', null, ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(401, $me['status']);
    }
}

