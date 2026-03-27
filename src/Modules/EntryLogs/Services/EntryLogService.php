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
            $inventoryStmt = $this->pdo->prepare(
                'SELECT id, quantity FROM inventory_items WHERE clinic_id = :clinic_id AND sku = :sku LIMIT 1'
            );
            $inventoryStmt->execute([
                'clinic_id' => $clinicId,
                'sku' => $dto->sku,
            ]);
            $inventoryItem = $inventoryStmt->fetch();

            if (!$inventoryItem) {
                $insStmt = $this->pdo->prepare(
                    'INSERT INTO inventory_items (clinic_id, sku, name, quantity, updated_at)
                     VALUES (:clinic_id, :sku, :name, 0, NOW())
                     ON CONFLICT (clinic_id, sku) DO NOTHING'
                );
                $insStmt->execute([
                    'clinic_id' => $clinicId,
                    'sku' => $dto->sku,
                    'name' => $dto->name ?? $dto->sku,
                ]);

                $inventoryStmt->execute([
                    'clinic_id' => $clinicId,
                    'sku' => $dto->sku,
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
                'INSERT INTO entry_logs (clinic_id, sku, quantity, note, created_by)
                 VALUES (:clinic_id, :sku, :quantity, :note, :created_by)
                 RETURNING id, clinic_id, sku, quantity, note, created_by, created_at'
            );
            $logStmt->execute([
                'clinic_id' => $clinicId,
                'sku' => $dto->sku,
                'quantity' => $dto->quantity,
                'note' => $dto->note,
                'created_by' => $userId,
            ]);

            $entryLog = $logStmt->fetch();
            $this->pdo->commit();

            return [
                'entry_log' => $entryLog,
                'inventory' => [
                    'sku' => $dto->sku,
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
            'SELECT id, clinic_id, sku, quantity, note, created_by, created_at
             FROM entry_logs
             WHERE clinic_id = :clinic_id
             ORDER BY id DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll() ?: [];
    }
}
