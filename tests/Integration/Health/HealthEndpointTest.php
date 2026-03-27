<?php

declare(strict_types=1);

namespace Tests\Integration\Health;

use Tests\Integration\Support\BaseApiTestCase;

final class HealthEndpointTest extends BaseApiTestCase
{
    public function testUpReturnsOkStatus(): void
    {
        $res = $this->request('GET', '/up');

        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertArrayHasKey('data', $res['json']);
        $this->assertSame('up', $res['json']['data']['status'] ?? null);
    }

    public function testUnknownRouteReturnsNotFound(): void
    {
        $res = $this->request('GET', '/this-route-does-not-exist');

        $this->assertSame(404, $res['status']);
        $this->assertIsArray($res['json']);
    }
}
