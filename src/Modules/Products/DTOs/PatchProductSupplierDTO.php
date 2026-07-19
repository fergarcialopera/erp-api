<?php

declare(strict_types=1);

namespace App\Modules\Products\DTOs;

final class PatchProductSupplierDTO
{
    public function __construct(
        public readonly bool $supplierIdTouched,
        public readonly ?string $supplierId,
        public readonly bool $supplierReferenceTouched,
        public readonly ?string $supplierReference,
        public readonly bool $purchasePriceTouched,
        public readonly ?float $purchasePrice,
        public readonly bool $pvpTouched,
        public readonly ?float $pvp,
        public readonly bool $netCostTouched,
        public readonly ?float $netCost,
        public readonly ?bool $isPreferred,
    ) {
    }
}
