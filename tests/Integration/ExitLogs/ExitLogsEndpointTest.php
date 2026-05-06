<?php

declare(strict_types=1);

namespace Tests\Integration\ExitLogs;

use Tests\Integration\Support\BaseApiTestCase;

final class ExitLogsEndpointTest extends BaseApiTestCase
{
    private const PRODUCT_A1 = '01KBASELINEPRODA0000000001';

    public function testCreateExitLogWithoutTokenReturns401(): void
    {
        $res = $this->request('POST', '/api/v1/exit-logs', [
            'items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 1]],
        ]);
        $this->assertSame(401, $res['status']);
    }

    public function testCreateExitLogAllowedForStaff(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 1]]],
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(201, $created['status'], $created['raw'] ?? '');
        $this->assertSame('DRAFT', $created['json']['data']['exit_log']['status'] ?? null);
    }

    public function testConfirmExitLogInsufficientStock(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 99999]]],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');
        $this->assertNotSame('', $exitId);

        $res = $this->request(
            'POST',
            '/api/v1/exit-logs/' . $exitId . '/confirm',
            null,
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(422, $res['status']);
    }

    public function testExitLogsAreIsolatedByClinic(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 1]]],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');

        $listA = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('staff@clinic.local'));
        $listB = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('staff2@clinic.local'));

        $idsA = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $listA['json']['data'] ?? []);
        $idsB = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $listB['json']['data'] ?? []);

        $this->assertContains($exitId, $idsA);
        $this->assertNotContains($exitId, $idsB);
    }

    public function testPatchAllQuantitiesToZeroCancelsExitLog(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 2]]],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');
        $itemId = (string) (($created['json']['data']['items'][0]['id'] ?? ''));

        $patch = $this->request(
            'PATCH',
            '/api/v1/exit-logs/' . $exitId,
            ['items' => [['item_id' => (int) $itemId, 'quantity' => 0]]],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(200, $patch['status'], $patch['raw'] ?? '');
        $this->assertSame('CANCELLED', $patch['json']['data']['exit_log']['status'] ?? null);
    }

    public function testGetExitLogReturnsEnrichedPayload(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 1]]],
            $this->authHeaderFor('admin@clinic.local')
        );
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');

        $get = $this->request('GET', '/api/v1/exit-logs/' . $exitId, null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $get['status']);
        $item = $get['json']['data']['items'][0] ?? null;
        $this->assertIsArray($item);
        $this->assertArrayHasKey('product', $item);
        $this->assertArrayHasKey('requested_quantity', $item);
    }
}
