<?php

namespace App\Modules\Inventory\Validators;

use App\Modules\Inventory\DTOs\UpsertInventoryItemDTO;
use InvalidArgumentException;

final class InventoryValidator
{
    public function validateUpsert(array $payload): UpsertInventoryItemDTO
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);

        if ($sku === '') {
            throw new InvalidArgumentException('Invalid sku');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }

        if ($quantity === false || $quantity < 0) {
            throw new InvalidArgumentException('Invalid quantity');
        }

        return new UpsertInventoryItemDTO($sku, $name, (int) $quantity);
    }
}
