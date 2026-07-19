<?php

declare(strict_types=1);

namespace App\Modules\Products\DTOs;

final class UpsertProductSupplierDTO
{
    public function __construct(
        public readonly string $supplierId,
        public readonly ?string $supplierReference,
        public readonly ?float $purchasePrice,
        public readonly ?float $pvp,
        public readonly ?float $netCost,
        public readonly bool $isPreferred,
    ) {
    }
}
