<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Tests\Integration\Support\BaseApiTestCase;

final class CorsHeadersTest extends BaseApiTestCase
{
    public function testPreflightReturnsConfiguredAllowOrigin(): void
    {
        $allowedOrigin = rtrim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:3000'), '/');

        $res = $this->request('OPTIONS', '/api/v1/auth/login', null, [
            'Origin' => $allowedOrigin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ]);

        $this->assertSame(204, $res['status']);
        $this->assertSame($allowedOrigin, $res['headers']['access-control-allow-origin'] ?? null);
        $this->assertStringContainsString('POST', $res['headers']['access-control-allow-methods'] ?? '');
    }

    public function testGetResponseIncludesCorsHeaders(): void
    {
        $allowedOrigin = rtrim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:3000'), '/');

        $res = $this->request('GET', '/up', null, [
            'Origin' => $allowedOrigin,
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertSame($allowedOrigin, $res['headers']['access-control-allow-origin'] ?? null);
    }

    public function testPreflightDoesNotReflectArbitraryOrigin(): void
    {
        $allowedOrigin = rtrim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:3000'), '/');

        $res = $this->request('OPTIONS', '/api/v1/auth/login', null, [
            'Origin' => 'http://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame(204, $res['status']);
        $this->assertSame($allowedOrigin, $res['headers']['access-control-allow-origin'] ?? null);
    }
}
