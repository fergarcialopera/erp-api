<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\DTOs\UpsertInventoryItemDTO;
use PDO;

final class InventoryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listByClinic(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, sku, name, quantity, updated_at
             FROM inventory_items
             WHERE clinic_id = :clinic_id
             ORDER BY id DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll() ?: [];
    }

    public function upsertByClinic(string $clinicId, UpsertInventoryItemDTO $dto): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_items (clinic_id, sku, name, quantity)
             VALUES (:clinic_id, :sku, :name, :quantity)
             ON CONFLICT (clinic_id, sku)
             DO UPDATE SET
                name = EXCLUDED.name,
                quantity = EXCLUDED.quantity,
                updated_at = NOW()
             RETURNING id, clinic_id, sku, name, quantity, updated_at'
        );

        $stmt->execute([
            'clinic_id' => $clinicId,
            'sku' => $dto->sku,
            'name' => $dto->name,
            'quantity' => $dto->quantity,
        ]);

        $item = $stmt->fetch();
        return is_array($item) ? $item : [];
    }
}
