<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\DTOs\AdjustInventoryLocationDTO;
use App\Modules\Inventory\DTOs\UpsertInventoryItemDTO;
use PDO;
use RuntimeException;

final class InventoryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listByClinic(string $clinicId): array
    {
        $rows = $this->fetchAggregatedLocationRows($clinicId, null, false);
        $byProduct = $this->aggregateRowsByProduct($rows);

        $result = array_values($byProduct);
        usort(
            $result,
            static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''))
        );

        foreach ($result as &$item) {
            unset($item['updated_at']);
        }
        unset($item);

        return $result;
    }

    /**
     * @return array{product: array{id: string, sku: string, name: string}, quantity_total: int, locations: list<array<string, mixed>>}|null
     */
    public function stockLocationsForProduct(string $clinicId, string $productId): ?array
    {
        $productStmt = $this->pdo->prepare(
            'SELECT id::text AS id, sku, name
             FROM products
             WHERE clinic_id = :clinic_id AND id::text = :product_id
             LIMIT 1'
        );
        $productStmt->execute(['clinic_id' => $clinicId, 'product_id' => $productId]);
        $product = $productStmt->fetch();
        if (!is_array($product)) {
            return null;
        }

        $rows = $this->fetchAggregatedLocationRows($clinicId, $productId, true);
        $locations = [];
        $quantityTotal = 0;

        foreach ($rows as $row) {
            $quantity = (int) ($row['quantity'] ?? 0);
            $quantityTotal += $quantity;
            $locations[] = $this->mapLocationFromRow($row);
        }

        $this->sortLocationsForPicker($locations);

        return [
            'product' => [
                'id' => (string) ($product['id'] ?? $productId),
                'sku' => (string) ($product['sku'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
            ],
            'quantity_total' => $quantityTotal,
            'locations' => $locations,
        ];
    }

    /**
     * @param list<AdjustInventoryLocationDTO> $locations
     * @return array{product: array{id: string, sku: string, name: string}, quantity_total: int, locations: list<array<string, mixed>>}|null
     */
    public function adjustProductQuantities(string $clinicId, string $productId, array $locations): ?array
    {
        $productStmt = $this->pdo->prepare(
            'SELECT id FROM products WHERE clinic_id = :clinic_id AND id::text = :product_id LIMIT 1'
        );
        $productStmt->execute(['clinic_id' => $clinicId, 'product_id' => $productId]);
        if (!$productStmt->fetch()) {
            return null;
        }

        $this->pdo->beginTransaction();

        try {
            foreach ($locations as $location) {
                $this->setInventoryQuantity(
                    $clinicId,
                    $productId,
                    $location->compartmentId,
                    $location->quantity
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        return $this->stockLocationsForProduct($clinicId, $productId);
    }

    public function upsertByClinic(string $clinicId, UpsertInventoryItemDTO $dto): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_items (clinic_id, product_id, quantity)
             VALUES (:clinic_id, :product_id, :quantity)
             ON CONFLICT (clinic_id, product_id) WHERE compartment_id IS NULL
             DO UPDATE SET
                quantity = EXCLUDED.quantity,
                updated_at = NOW()
             RETURNING id::text AS id, clinic_id, product_id, quantity, updated_at'
        );

        $productStmt = $this->pdo->prepare(
            'SELECT id FROM products WHERE clinic_id = :clinic_id AND sku = :sku LIMIT 1'
        );
        $productStmt->execute([
            'clinic_id' => $clinicId,
            'sku' => $dto->sku,
        ]);
        $product = $productStmt->fetch();
        if (!is_array($product) || !isset($product['id'])) {
            return [];
        }

        $stmt->execute([
            'clinic_id' => $clinicId,
            'product_id' => $product['id'],
            'quantity' => $dto->quantity,
        ]);

        $item = $stmt->fetch();
        return is_array($item) ? $item : [];
    }

    private function setInventoryQuantity(
        string $clinicId,
        string $productId,
        ?string $compartmentId,
        int $quantity
    ): void {
        if ($compartmentId !== null) {
            $compartmentStmt = $this->pdo->prepare(
                'SELECT is_active FROM compartments WHERE id = :id AND clinic_id = :clinic_id LIMIT 1'
            );
            $compartmentStmt->execute(['id' => $compartmentId, 'clinic_id' => $clinicId]);
            $compartment = $compartmentStmt->fetch();
            if (!is_array($compartment)) {
                throw new RuntimeException('Compartment not found in clinic');
            }
            if (!(bool) $compartment['is_active']) {
                throw new RuntimeException('Compartment is inactive');
            }
        }

        $inventoryItem = $this->findOrCreateInventoryRow($clinicId, $productId, $compartmentId);

        $updateStmt = $this->pdo->prepare(
            'UPDATE inventory_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id'
        );
        $updateStmt->execute([
            'quantity' => $quantity,
            'id' => $inventoryItem['id'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrCreateInventoryRow(string $clinicId, string $productId, ?string $compartmentId): array
    {
        if ($compartmentId !== null) {
            $inventoryStmt = $this->pdo->prepare(
                'SELECT id, quantity
                 FROM inventory_items
                 WHERE clinic_id = :clinic_id
                   AND product_id = :product_id
                   AND compartment_id = :compartment_id
                 LIMIT 1'
            );
            $inventoryStmt->execute([
                'clinic_id' => $clinicId,
                'product_id' => $productId,
                'compartment_id' => $compartmentId,
            ]);
            $inventoryItem = $inventoryStmt->fetch();

            if (!$inventoryItem) {
                $insStmt = $this->pdo->prepare(
                    'INSERT INTO inventory_items (clinic_id, product_id, compartment_id, quantity, updated_at)
                     VALUES (:clinic_id, :product_id, :compartment_id, 0, NOW())
                     RETURNING id, quantity'
                );
                $insStmt->execute([
                    'clinic_id' => $clinicId,
                    'product_id' => $productId,
                    'compartment_id' => $compartmentId,
                ]);
                $inventoryItem = $insStmt->fetch();
            }
        } else {
            $inventoryStmt = $this->pdo->prepare(
                'SELECT id, quantity
                 FROM inventory_items
                 WHERE clinic_id = :clinic_id
                   AND product_id = :product_id
                   AND compartment_id IS NULL
                 LIMIT 1'
            );
            $inventoryStmt->execute([
                'clinic_id' => $clinicId,
                'product_id' => $productId,
            ]);
            $inventoryItem = $inventoryStmt->fetch();

            if (!$inventoryItem) {
                $insStmt = $this->pdo->prepare(
                    'INSERT INTO inventory_items (clinic_id, product_id, quantity, updated_at)
                     VALUES (:clinic_id, :product_id, 0, NOW())
                     ON CONFLICT (clinic_id, product_id) WHERE compartment_id IS NULL DO NOTHING'
                );
                $insStmt->execute([
                    'clinic_id' => $clinicId,
                    'product_id' => $productId,
                ]);

                $inventoryStmt->execute([
                    'clinic_id' => $clinicId,
                    'product_id' => $productId,
                ]);
                $inventoryItem = $inventoryStmt->fetch();
            }
        }

        if (!is_array($inventoryItem)) {
            throw new RuntimeException('Inventory item not found');
        }

        return $inventoryItem;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAggregatedLocationRows(string $clinicId, ?string $productId, bool $positiveQuantityOnly): array
    {
        $sql = 'SELECT
                ii.product_id::text AS product_id,
                p.sku,
                p.name,
                ii.compartment_id::text AS compartment_id,
                c.code AS compartment_code,
                l.id::text AS ambiente_id,
                l.name AS ambiente_name,
                COALESCE(SUM(ii.quantity), 0)::int AS quantity,
                MAX(ii.updated_at) AS updated_at
             FROM inventory_items ii
             INNER JOIN products p ON p.id = ii.product_id
             LEFT JOIN compartments c ON c.id = ii.compartment_id
             LEFT JOIN ambientes l ON l.id = c.ambiente_id
             WHERE ii.clinic_id = :clinic_id';

        $params = ['clinic_id' => $clinicId];

        if ($productId !== null) {
            $sql .= ' AND ii.product_id = :product_id';
            $params['product_id'] = $productId;
        }

        if ($positiveQuantityOnly) {
            $sql .= ' AND ii.quantity > 0';
        }

        $sql .= ' GROUP BY ii.product_id, p.sku, p.name, ii.compartment_id, c.code, l.id, l.name
             ORDER BY MAX(ii.updated_at) DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function aggregateRowsByProduct(array $rows): array
    {
        $byProduct = [];
        foreach ($rows as $row) {
            $productId = (string) ($row['product_id'] ?? '');
            if ($productId === '') {
                continue;
            }

            if (!isset($byProduct[$productId])) {
                $byProduct[$productId] = [
                    'product' => [
                        'id' => $productId,
                        'sku' => (string) ($row['sku'] ?? ''),
                        'name' => (string) ($row['name'] ?? ''),
                    ],
                    'quantity_total' => 0,
                    'updated_at' => $row['updated_at'] ?? null,
                    'locations' => [],
                ];
            }

            $quantity = (int) ($row['quantity'] ?? 0);
            $byProduct[$productId]['quantity_total'] += $quantity;

            $updatedAt = $row['updated_at'] ?? null;
            if ($updatedAt !== null) {
                $currentUpdatedAt = $byProduct[$productId]['updated_at'];
                if ($currentUpdatedAt === null || (string) $updatedAt > (string) $currentUpdatedAt) {
                    $byProduct[$productId]['updated_at'] = $updatedAt;
                }
            }

            $byProduct[$productId]['locations'][] = $this->mapLocationFromRow($row);
        }

        return $byProduct;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{quantity: int, compartment: ?array{id: string, code: string}, ambiente: ?array{id: string, name: string}}
     */
    private function mapLocationFromRow(array $row): array
    {
        $compartmentId = $row['compartment_id'] !== null ? (string) $row['compartment_id'] : null;
        $ambienteId = $row['ambiente_id'] !== null ? (string) $row['ambiente_id'] : null;

        return [
            'quantity' => (int) ($row['quantity'] ?? 0),
            'compartment' => $compartmentId !== null ? [
                'id' => $compartmentId,
                'code' => $row['compartment_code'] !== null ? (string) $row['compartment_code'] : '',
            ] : null,
            'ambiente' => $ambienteId !== null ? [
                'id' => $ambienteId,
                'name' => $row['ambiente_name'] !== null ? (string) $row['ambiente_name'] : '',
            ] : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $locations
     */
    private function sortLocationsForPicker(array &$locations): void
    {
        usort(
            $locations,
            static function (array $a, array $b): int {
                $aUnassigned = ($a['compartment'] ?? null) === null;
                $bUnassigned = ($b['compartment'] ?? null) === null;
                if ($aUnassigned !== $bUnassigned) {
                    return $aUnassigned ? 1 : -1;
                }

                return ((int) ($b['quantity'] ?? 0)) <=> ((int) ($a['quantity'] ?? 0));
            }
        );
    }
}
