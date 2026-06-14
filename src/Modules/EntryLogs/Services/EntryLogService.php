<?php

namespace App\Modules\EntryLogs\Services;

use App\Application\Stock\LocationPresenter;
use App\Application\Stock\LocationValidator;
use App\Modules\EntryLogs\DTOs\CreateEntryLogDTO;
use PDO;
use RuntimeException;

final class EntryLogService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocationValidator $locationValidator
    ) {
    }

    public function create(string $clinicId, string $userId, CreateEntryLogDTO $dto): array
    {
        if ($dto->compartmentId !== null) {
            $this->locationValidator->assertCompartmentInClinic($clinicId, $dto->compartmentId);
        }

        $this->pdo->beginTransaction();

        try {
            $productStmt = $this->pdo->prepare(
                'SELECT id, sku, name FROM products WHERE clinic_id = :clinic_id AND sku = :sku LIMIT 1'
            );
            $productStmt->execute([
                'clinic_id' => $clinicId,
                'sku' => $dto->sku,
            ]);
            $product = $productStmt->fetch();
            if (!is_array($product) || !isset($product['id'])) {
                throw new RuntimeException('Product not found for SKU');
            }

            $inventoryItem = $this->findOrCreateInventoryRow(
                $clinicId,
                (string) $product['id'],
                $dto->compartmentId
            );

            $newQuantity = (int) $inventoryItem['quantity'] + $dto->quantity;
            $updateStmt = $this->pdo->prepare(
                'UPDATE inventory_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id'
            );
            $updateStmt->execute([
                'quantity' => $newQuantity,
                'id' => $inventoryItem['id'],
            ]);

            $logStmt = $this->pdo->prepare(
                'INSERT INTO entry_logs (clinic_id, product_id, quantity, note, created_by_user_id, compartment_id)
                 VALUES (:clinic_id, :product_id, :quantity, :note, :created_by_user_id, :compartment_id)
                 RETURNING id, clinic_id, product_id, quantity, note, created_by_user_id, compartment_id, created_at'
            );
            $logStmt->execute([
                'clinic_id' => $clinicId,
                'product_id' => $product['id'],
                'quantity' => $dto->quantity,
                'note' => $dto->note,
                'created_by_user_id' => $userId,
                'compartment_id' => $dto->compartmentId,
            ]);

            $entryLog = $logStmt->fetch();
            $this->pdo->commit();

            $location = $dto->compartmentId !== null
                ? ($this->locationValidator->fetchLocationForCompartment($clinicId, $dto->compartmentId)
                    ?? LocationPresenter::empty())
                : LocationPresenter::empty();

            return [
                'entry_log' => $this->mapEntryLogRow(
                    is_array($entryLog) ? $entryLog : [],
                    (string) $product['sku'],
                    (string) $product['name'],
                    $location
                ),
                'inventory' => [
                    'sku' => (string) $product['sku'],
                    'quantity' => $newQuantity,
                    'compartment_id' => $dto->compartmentId,
                    'ambiente' => $location['ambiente'],
                    'compartment' => $location['compartment'],
                ],
            ];
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function list(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                el.id,
                el.clinic_id,
                p.sku,
                p.name,
                el.quantity,
                el.note,
                el.created_by_user_id AS created_by,
                el.created_at,
                el.compartment_id,
                c.code AS compartment_code,
                l.id AS ambiente_id,
                l.name AS ambiente_name,
                l.device_id AS ambiente_device_id
             FROM entry_logs el
             INNER JOIN products p ON p.id = el.product_id
             LEFT JOIN compartments c ON c.id = el.compartment_id AND c.clinic_id = :clinic_id
             LEFT JOIN ambientes l ON l.id = c.ambiente_id AND l.clinic_id = :clinic_id
             WHERE el.clinic_id = :clinic_id
             ORDER BY el.id DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $location = LocationPresenter::fromJoinRow($row);
            $out[] = [
                'id' => (string) $row['id'],
                'clinic_id' => (string) $row['clinic_id'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'quantity' => (int) $row['quantity'],
                'note' => $row['note'],
                'created_by' => $row['created_by'],
                'created_at' => $row['created_at'],
                'ambiente' => $location['ambiente'],
                'compartment' => $location['compartment'],
            ];
        }

        return $out;
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
     * @param array<string, mixed> $row
     * @param array{ambiente: ?array, compartment: ?array} $location
     * @return array<string, mixed>
     */
    private function mapEntryLogRow(array $row, string $sku, string $name, array $location): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'clinic_id' => (string) ($row['clinic_id'] ?? ''),
            'sku' => $sku,
            'name' => $name,
            'quantity' => (int) ($row['quantity'] ?? 0),
            'note' => $row['note'] ?? null,
            'created_by' => $row['created_by_user_id'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'ambiente' => $location['ambiente'],
            'compartment' => $location['compartment'],
        ];
    }
}
