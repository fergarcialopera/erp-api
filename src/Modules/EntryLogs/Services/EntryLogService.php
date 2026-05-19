<?php

namespace App\Modules\EntryLogs\Services;

use App\Modules\EntryLogs\DTOs\CreateEntryLogDTO;
use PDO;
use RuntimeException;

final class EntryLogService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $clinicId, string $userId, CreateEntryLogDTO $dto): array
    {
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
                'product_id' => $product['id'],
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
                    'product_id' => $product['id'],
                ]);

                $inventoryStmt->execute([
                    'clinic_id' => $clinicId,
                    'product_id' => $product['id'],
                ]);
                $inventoryItem = $inventoryStmt->fetch();
                if (!$inventoryItem) {
                    throw new RuntimeException('Inventory item not found');
                }
            }

            $newQuantity = (int) $inventoryItem['quantity'] + $dto->quantity;
            $updateStmt = $this->pdo->prepare(
                'UPDATE inventory_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id'
            );
            $updateStmt->execute([
                'quantity' => $newQuantity,
                'id' => $inventoryItem['id'],
            ]);

            $logStmt = $this->pdo->prepare(
                'INSERT INTO entry_logs (clinic_id, product_id, quantity, note, created_by_user_id)
                 VALUES (:clinic_id, :product_id, :quantity, :note, :created_by_user_id)
                 RETURNING id, clinic_id, product_id, quantity, note, created_by_user_id, created_at'
            );
            $logStmt->execute([
                'clinic_id' => $clinicId,
                'product_id' => $product['id'],
                'quantity' => $dto->quantity,
                'note' => $dto->note,
                'created_by_user_id' => $userId,
            ]);

            $entryLog = $logStmt->fetch();
            $this->pdo->commit();

            return [
                'entry_log' => $entryLog,
                'inventory' => [
                    'sku' => (string) $product['sku'],
                    'quantity' => $newQuantity,
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
            'SELECT el.id, el.clinic_id, p.sku, p.name, el.quantity, el.note, el.created_by_user_id AS created_by, el.created_at
             FROM entry_logs el
             INNER JOIN products p ON p.id = el.product_id
             WHERE el.clinic_id = :clinic_id
             ORDER BY id DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll() ?: [];
    }
}
