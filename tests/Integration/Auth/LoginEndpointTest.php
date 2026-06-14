<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use Tests\Integration\Support\BaseApiTestCase;

final class LoginEndpointTest extends BaseApiTestCase
{
    public function testLoginSuccess(): void
    {
        $res = $this->login('admin@clinic-erp.com');

        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertArrayHasKey('data', $res['json']);
        $this->assertIsArray($res['json']['data']);
        $this->assertArrayHasKey('access_token', $res['json']['data']);
        $this->assertSame('Bearer', $res['json']['data']['token_type'] ?? null);
        $this->assertSame(1800, $res['json']['data']['expires_in'] ?? null);
    }

    public function testLoginWithUnknownUserFails(): void
    {
        $res = $this->login('unknown@clinic-erp.com');
        $this->assertSame(401, $res['status']);
        $this->assertIsArray($res['json']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $res = $this->login('admin@clinic-erp.com', 'wrong-password');
        $this->assertSame(401, $res['status']);
        $this->assertIsArray($res['json']);
    }

    public function testLoginWithInvalidPayloadFails(): void
    {
        $res = $this->request('POST', '/api/v1/auth/login', ['email' => 'not-an-email']);
        $this->assertSame(422, $res['status']);
        $this->assertIsArray($res['json']);
    }
}
