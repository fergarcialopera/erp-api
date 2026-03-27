<?php

namespace App\Modules\Inventory\DTOs;

final class UpsertInventoryItemDTO
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $quantity
    ) {
    }
}
