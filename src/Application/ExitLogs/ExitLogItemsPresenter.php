<?php

declare(strict_types=1);

namespace App\Application\ExitLogs;

final class ExitLogItemsPresenter
{
    /**
     * @param list<array{
     *     item_id: string,
     *     product: array{id: string, name: string, sku: ?string, barcode: null},
     *     ambiente: ?array{id: string, name: string, device_id: ?string},
     *     compartment: ?array{id: string, code: string},
     *     requested_quantity: int,
     *     confirmed_quantity: ?int,
     *     stock_available: ?int
     * }> $lines
     * @return list<array{
     *     product: array{id: string, name: string, sku: ?string, barcode: null},
     *     requested_quantity_total: int,
     *     confirmed_quantity_total: ?int,
     *     locations: list<array{
     *         item_id: string,
     *         requested_quantity: int,
     *         confirmed_quantity: ?int,
     *         stock_available: ?int,
     *         ambiente: ?array{id: string, name: string, device_id: ?string},
     *         compartment: ?array{id: string, code: string}
     *     }>
     * }>
     */
    public static function groupByProduct(array $lines): array
    {
        /** @var array<string, array<string, mixed>> $byProduct */
        $byProduct = [];

        foreach ($lines as $line) {
            $productId = (string) $line['product']['id'];
            if (!isset($byProduct[$productId])) {
                $byProduct[$productId] = [
                    'product' => $line['product'],
                    'requested_quantity_total' => 0,
                    'confirmed_quantity_total' => 0,
                    'confirmed_all_set' => true,
                    'locations' => [],
                ];
            }

            $group = &$byProduct[$productId];
            $requested = (int) $line['requested_quantity'];
            $group['requested_quantity_total'] += $requested;

            $confirmed = $line['confirmed_quantity'];
            if ($confirmed === null) {
                $group['confirmed_all_set'] = false;
            } else {
                $group['confirmed_quantity_total'] += (int) $confirmed;
            }

            $group['locations'][] = [
                'item_id' => $line['item_id'],
                'requested_quantity' => $requested,
                'confirmed_quantity' => $confirmed,
                'stock_available' => $line['stock_available'],
                'ambiente' => $line['ambiente'],
                'compartment' => $line['compartment'],
            ];
            unset($group);
        }

        $out = [];
        foreach ($byProduct as $group) {
            if (!$group['confirmed_all_set']) {
                $group['confirmed_quantity_total'] = null;
            }
            unset($group['confirmed_all_set']);
            $out[] = $group;
        }

        return $out;
    }
}
