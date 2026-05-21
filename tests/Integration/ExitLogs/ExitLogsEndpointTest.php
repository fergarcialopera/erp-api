<?php

declare(strict_types=1);

namespace Tests\Integration\ExitLogs;

use Tests\Integration\Support\BaseApiTestCase;

final class ExitLogsEndpointTest extends BaseApiTestCase
{
    private const PRODUCT_A1 = '10000000-0000-4000-8000-000000000001';
    private const COMPARTMENT_A1 = '50000000-0000-4000-8000-000000000001';
    private const LOCKER_A1 = '40000000-0000-4000-8000-000000000001';

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
            ['items' => [['item_id' => $itemId, 'quantity' => 0]]],
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

    public function testCreateExitLogWithCompartmentReturnsLocationOnListAndDetail(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'quantity' => 1,
                    'compartment_id' => self::COMPARTMENT_A1,
                    'locker_id' => self::LOCKER_A1,
                ]],
            ],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');
        $item = $created['json']['data']['items'][0] ?? null;
        $this->assertIsArray($item);
        $this->assertSame(self::COMPARTMENT_A1, $item['compartment']['id'] ?? null);
        $this->assertSame(self::LOCKER_A1, $item['locker']['id'] ?? null);

        $list = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('staff@clinic.local'));
        $row = null;
        foreach (($list['json']['data'] ?? []) as $r) {
            if (($r['id'] ?? '') === $exitId) {
                $row = $r;
                break;
            }
        }
        $this->assertIsArray($row);
        $this->assertSame(self::COMPARTMENT_A1, $row['location']['compartment']['id'] ?? null);

        $get = $this->request('GET', '/api/v1/exit-logs/' . $exitId, null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $get['status']);
        $this->assertSame(self::COMPARTMENT_A1, $get['json']['data']['exit_log']['location']['compartment']['id'] ?? null);
    }

    public function testConfirmExitLogDeductsFromCompartment(): void
    {
        $pdo = self::testPdo();
        $stmt = $pdo->prepare(
            'SELECT quantity FROM inventory_items
             WHERE clinic_id = :clinic_id AND product_id = :product_id AND compartment_id = :compartment_id'
        );
        $stmt->execute([
            'clinic_id' => '11111111-1111-1111-1111-111111111111',
            'product_id' => self::PRODUCT_A1,
            'compartment_id' => self::COMPARTMENT_A1,
        ]);
        $before = (int) ($stmt->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $before);

        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'quantity' => 1,
                    'compartment_id' => self::COMPARTMENT_A1,
                ]],
            ],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');

        $confirm = $this->request(
            'POST',
            '/api/v1/exit-logs/' . $exitId . '/confirm',
            null,
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(200, $confirm['status'], $confirm['raw'] ?? '');

        $stmt->execute([
            'clinic_id' => '11111111-1111-1111-1111-111111111111',
            'product_id' => self::PRODUCT_A1,
            'compartment_id' => self::COMPARTMENT_A1,
        ]);
        $after = (int) ($stmt->fetchColumn() ?: 0);
        $this->assertSame($before - 1, $after);
    }
}
