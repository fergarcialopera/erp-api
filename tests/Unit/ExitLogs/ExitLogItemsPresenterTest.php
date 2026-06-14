<?php

declare(strict_types=1);

namespace Tests\Unit\ExitLogs;

use App\Application\ExitLogs\ExitLogItemsPresenter;
use PHPUnit\Framework\TestCase;

final class ExitLogItemsPresenterTest extends TestCase
{
    public function testGroupByProductMergesLocationsAndTotals(): void
    {
        $product = [
            'id' => '10000000-0000-4000-8000-000000000001',
            'name' => 'Guantes',
            'sku' => 'SR-GLV-001',
            'barcode' => null,
        ];

        $grouped = ExitLogItemsPresenter::groupByProduct([
            [
                'item_id' => 'line-1',
                'product' => $product,
                'ambiente' => null,
                'compartment' => ['id' => 'c1', 'code' => 'A1-C1'],
                'requested_quantity' => 1,
                'confirmed_quantity' => null,
                'stock_available' => 10,
            ],
            [
                'item_id' => 'line-2',
                'product' => $product,
                'ambiente' => null,
                'compartment' => ['id' => 'c2', 'code' => 'A1-C2'],
                'requested_quantity' => 2,
                'confirmed_quantity' => null,
                'stock_available' => 5,
            ],
        ]);

        $this->assertCount(1, $grouped);
        $this->assertSame($product, $grouped[0]['product']);
        $this->assertSame(3, $grouped[0]['requested_quantity_total']);
        $this->assertNull($grouped[0]['confirmed_quantity_total']);
        $this->assertCount(2, $grouped[0]['locations']);
        $this->assertSame('line-1', $grouped[0]['locations'][0]['item_id']);
        $this->assertSame('c2', $grouped[0]['locations'][1]['compartment']['id'] ?? null);
    }

    public function testGroupByProductSumsConfirmedWhenAllLinesHaveValue(): void
    {
        $product = [
            'id' => 'p1',
            'name' => 'X',
            'sku' => null,
            'barcode' => null,
        ];

        $grouped = ExitLogItemsPresenter::groupByProduct([
            [
                'item_id' => '1',
                'product' => $product,
                'ambiente' => null,
                'compartment' => null,
                'requested_quantity' => 2,
                'confirmed_quantity' => 2,
                'stock_available' => null,
            ],
            [
                'item_id' => '2',
                'product' => $product,
                'ambiente' => null,
                'compartment' => null,
                'requested_quantity' => 1,
                'confirmed_quantity' => 1,
                'stock_available' => null,
            ],
        ]);

        $this->assertSame(3, $grouped[0]['requested_quantity_total']);
        $this->assertSame(3, $grouped[0]['confirmed_quantity_total']);
    }
}
