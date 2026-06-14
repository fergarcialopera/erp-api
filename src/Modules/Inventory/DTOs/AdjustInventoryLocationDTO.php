<?php

namespace App\Modules\Inventory\DTOs;

final class AdjustInventoryLocationDTO
{
    public function __construct(
        public readonly int $quantity,
        public readonly ?string $zoneId
    ) {
    }
}
