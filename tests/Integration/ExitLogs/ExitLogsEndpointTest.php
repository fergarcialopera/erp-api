<?php

declare(strict_types=1);

namespace Tests\Integration\ExitLogs;

use Tests\Integration\Support\BaseApiTestCase;

final class ExitLogsEndpointTest extends BaseApiTestCase
{
    private const CLINIC_A = '11111111-1111-1111-1111-111111111111';
    private const PRODUCT_A1 = '10000000-0000-4000-8000-000000000001';
    private const ZONE_A1 = '50000000-0000-4000-8000-000000000001';
    private const ZONE_A2 = '50000000-0000-4000-8000-000000000002';
    private const AMBIENTE_A1 = '40000000-0000-4000-8000-000000000001';

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

        $listAdmin = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('admin@clinic.local'));
        $listB = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('staff2@clinic.local'));

        $idsAdmin = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $listAdmin['json']['data'] ?? []);
        $idsB = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $listB['json']['data'] ?? []);

        $this->assertContains($exitId, $idsAdmin);
        $this->assertNotContains($exitId, $idsB);
    }

    public function testStaffListExitLogsOnlyOwnRecords(): void
    {
        $adminCreated = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 1]]],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(201, $adminCreated['status']);
        $adminExitId = (string) ($adminCreated['json']['data']['exit_log']['id'] ?? '');

        $staffCreated = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 1]]],
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(201, $staffCreated['status']);
        $staffExitId = (string) ($staffCreated['json']['data']['exit_log']['id'] ?? '');

        $listStaff = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(200, $listStaff['status']);
        $ids = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $listStaff['json']['data'] ?? []);

        $this->assertContains($staffExitId, $ids);
        $this->assertNotContains($adminExitId, $ids);

        foreach ($listStaff['json']['data'] ?? [] as $row) {
            $this->assertSame(
                '44444444-4444-4444-4444-444444444444',
                (string) ($row['created_by_user_id'] ?? '')
            );
        }
    }

    public function testStaffCannotGetOtherUserExitLog(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            ['items' => [['product_id' => self::PRODUCT_A1, 'quantity' => 1]]],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');

        $res = $this->request(
            'GET',
            '/api/v1/exit-logs/' . $exitId,
            null,
            $this->authHeaderFor('staff@clinic.local')
        );
        $this->assertSame(404, $res['status']);
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
        $itemId = (string) ($created['json']['data']['items'][0]['locations'][0]['item_id'] ?? '');

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

        $get = $this->request('GET', '/api/v1/exit-logs/' . $exitId, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $get['status']);
        $item = $get['json']['data']['items'][0] ?? null;
        $this->assertIsArray($item);
        $this->assertArrayHasKey('product', $item);
        $this->assertArrayHasKey('locations', $item);
        $this->assertArrayHasKey('requested_quantity_total', $item);
    }

    public function testCreateExitLogWithZoneReturnsLocationOnListAndDetail(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'quantity' => 1,
                    'zone_id' => self::ZONE_A1,
                    'ambiente_id' => self::AMBIENTE_A1,
                ]],
            ],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(201, $created['status']);
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');
        $item = $created['json']['data']['items'][0] ?? null;
        $this->assertIsArray($item);
        $location = $item['locations'][0] ?? null;
        $this->assertIsArray($location);
        $this->assertSame(self::ZONE_A1, $location['zone']['id'] ?? null);
        $this->assertSame(self::AMBIENTE_A1, $location['ambiente']['id'] ?? null);

        $list = $this->request('GET', '/api/v1/exit-logs', null, $this->authHeaderFor('admin@clinic.local'));
        $row = null;
        foreach (($list['json']['data'] ?? []) as $r) {
            if (($r['id'] ?? '') === $exitId) {
                $row = $r;
                break;
            }
        }
        $this->assertIsArray($row);
        $this->assertSame(self::ZONE_A1, $row['location']['zone']['id'] ?? null);

        $get = $this->request('GET', '/api/v1/exit-logs/' . $exitId, null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $get['status']);
        $this->assertSame(self::ZONE_A1, $get['json']['data']['exit_log']['location']['zone']['id'] ?? null);
    }

    public function testConfirmExitLogDeductsFromZone(): void
    {
        $pdo = self::testPdo();
        $stmt = $pdo->prepare(
            'SELECT quantity FROM inventory_items
             WHERE clinic_id = :clinic_id AND product_id = :product_id AND zone_id = :zone_id'
        );
        $stmt->execute([
            'clinic_id' => '11111111-1111-1111-1111-111111111111',
            'product_id' => self::PRODUCT_A1,
            'zone_id' => self::ZONE_A1,
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
                    'zone_id' => self::ZONE_A1,
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
            'clinic_id' => self::CLINIC_A,
            'product_id' => self::PRODUCT_A1,
            'zone_id' => self::ZONE_A1,
        ]);
        $after = (int) ($stmt->fetchColumn() ?: 0);
        $this->assertSame($before - 1, $after);
    }

    public function testCreateExitLogWithLocationsCreatesMultipleLinesForSameProduct(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'locations' => [
                        [
                            'zone_id' => self::ZONE_A1,
                            'quantity' => 1,
                            'ambiente_id' => self::AMBIENTE_A1,
                        ],
                        [
                            'zone_id' => self::ZONE_A2,
                            'quantity' => 2,
                        ],
                    ],
                ]],
            ],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(201, $created['status'], $created['raw'] ?? '');

        $items = $created['json']['data']['items'] ?? [];
        $this->assertCount(1, $items);
        $this->assertSame(self::PRODUCT_A1, $items[0]['product']['id'] ?? null);
        $this->assertSame(3, $items[0]['requested_quantity_total'] ?? null);

        $locations = $items[0]['locations'] ?? [];
        $this->assertCount(2, $locations);

        $zoneIds = array_map(static fn (array $row): ?string => $row['zone']['id'] ?? null, $locations);
        $this->assertContains(self::ZONE_A1, $zoneIds);
        $this->assertContains(self::ZONE_A2, $zoneIds);

        $quantities = array_map(static fn (array $row): int => (int) ($row['requested_quantity'] ?? 0), $locations);
        sort($quantities);
        $this->assertSame([1, 2], $quantities);
    }

    public function testConfirmExitLogWithLocationsDeductsFromMultipleZones(): void
    {
        $pdo = self::testPdo();
        $exists = $pdo->prepare(
            'SELECT id FROM inventory_items
             WHERE clinic_id = :clinic_id AND product_id = :product_id AND zone_id = :zone_id
             LIMIT 1'
        );
        $exists->execute([
            'clinic_id' => self::CLINIC_A,
            'product_id' => self::PRODUCT_A1,
            'zone_id' => self::ZONE_A2,
        ]);
        if ($exists->fetchColumn() === false) {
            $pdo->prepare(
                'INSERT INTO inventory_items (id, clinic_id, product_id, zone_id, quantity, updated_at)
                 VALUES (:id, :clinic_id, :product_id, :zone_id, :quantity, NOW())'
            )->execute([
                'id' => '30000000-0000-4000-8000-000000009901',
                'clinic_id' => self::CLINIC_A,
                'product_id' => self::PRODUCT_A1,
                'zone_id' => self::ZONE_A2,
                'quantity' => 50,
            ]);
        } else {
            $pdo->prepare(
                'UPDATE inventory_items
                 SET quantity = GREATEST(quantity, :quantity), updated_at = NOW()
                 WHERE clinic_id = :clinic_id AND product_id = :product_id AND zone_id = :zone_id'
            )->execute([
                'quantity' => 50,
                'clinic_id' => self::CLINIC_A,
                'product_id' => self::PRODUCT_A1,
                'zone_id' => self::ZONE_A2,
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT quantity FROM inventory_items
             WHERE clinic_id = :clinic_id AND product_id = :product_id AND zone_id = :zone_id'
        );

        $readQty = static function (string $zoneId) use ($stmt): int {
            $stmt->execute([
                'clinic_id' => self::CLINIC_A,
                'product_id' => self::PRODUCT_A1,
                'zone_id' => $zoneId,
            ]);

            return (int) ($stmt->fetchColumn() ?: 0);
        };

        $beforeC1 = $readQty(self::ZONE_A1);
        $beforeC2 = $readQty(self::ZONE_A2);
        $this->assertGreaterThan(0, $beforeC1);
        $this->assertGreaterThanOrEqual(2, $beforeC2);

        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'locations' => [
                        ['zone_id' => self::ZONE_A1, 'quantity' => 1],
                        ['zone_id' => self::ZONE_A2, 'quantity' => 2],
                    ],
                ]],
            ],
            $this->authHeaderFor('tech@clinic.local')
        );
        $this->assertSame(201, $created['status'], $created['raw'] ?? '');
        $exitId = (string) ($created['json']['data']['exit_log']['id'] ?? '');

        $confirm = $this->request(
            'POST',
            '/api/v1/exit-logs/' . $exitId . '/confirm',
            null,
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(200, $confirm['status'], $confirm['raw'] ?? '');

        $this->assertSame($beforeC1 - 1, $readQty(self::ZONE_A1));
        $this->assertSame($beforeC2 - 2, $readQty(self::ZONE_A2));
    }

    public function testCreateExitLogRejectsDuplicateZoneInLocations(): void
    {
        $res = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'locations' => [
                        ['zone_id' => self::ZONE_A1, 'quantity' => 1],
                        ['zone_id' => self::ZONE_A1, 'quantity' => 2],
                    ],
                ]],
            ],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(422, $res['status']);
    }

    public function testCreateExitLogRejectsLocationsAndQuantityTogether(): void
    {
        $res = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'quantity' => 1,
                    'locations' => [
                        ['zone_id' => self::ZONE_A1, 'quantity' => 1],
                    ],
                ]],
            ],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(422, $res['status']);
    }

    public function testCreateExitLogLegacyFormatStillAccepted(): void
    {
        $created = $this->request(
            'POST',
            '/api/v1/exit-logs',
            [
                'items' => [[
                    'product_id' => self::PRODUCT_A1,
                    'quantity' => 1,
                    'zone_id' => self::ZONE_A1,
                ]],
            ],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(201, $created['status'], $created['raw'] ?? '');
        $this->assertCount(1, $created['json']['data']['items'] ?? []);
        $this->assertSame(
            self::ZONE_A1,
            $created['json']['data']['items'][0]['locations'][0]['zone']['id'] ?? null
        );
    }
}
