<?php

namespace App\Modules\ExitLogs\Services;

use App\Modules\ExitLogs\DTOs\CreateExitLogDTO;
use PDO;
use RuntimeException;

final class ExitLogService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $clinicId, string $userId, CreateExitLogDTO $dto): array
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
                throw new RuntimeException('Inventory item not found');
            }

            $currentQuantity = (int) $inventoryItem['quantity'];
            if ($currentQuantity < $dto->quantity) {
                throw new RuntimeException('Insufficient stock');
            }

            $newQuantity = $currentQuantity - $dto->quantity;
            $updateStmt = $this->pdo->prepare(
                'UPDATE inventory_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id'
            );
            $updateStmt->execute([
                'quantity' => $newQuantity,
                'id' => $inventoryItem['id'],
            ]);

            $logStmt = $this->pdo->prepare(
                'INSERT INTO exit_logs (clinic_id, sku, quantity, note, created_by)
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

            $exitLog = $logStmt->fetch();
            $this->pdo->commit();

            return [
                'exit_log' => $exitLog,
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
             FROM exit_logs
             WHERE clinic_id = :clinic_id
             ORDER BY id DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll() ?: [];
    }
}
