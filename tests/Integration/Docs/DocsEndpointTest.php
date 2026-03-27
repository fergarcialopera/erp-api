<?php

declare(strict_types=1);

namespace Tests\Integration\Docs;

use Tests\Integration\Support\BaseApiTestCase;

final class DocsEndpointTest extends BaseApiTestCase
{
    public function testDocsYamlIsPublic(): void
    {
        $res = $this->request('GET', '/docs');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('openapi: 3.0.3', $res['raw']);
    }

    public function testDocsUiIsPublic(): void
    {
        $res = $this->request('GET', '/docs/ui');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('swagger-ui', strtolower($res['raw']));
    }
}
