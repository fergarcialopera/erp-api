<?php

declare(strict_types=1);

namespace App\Modules\Products\DTOs;

final class PatchProductDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?bool $isActive,
        public readonly bool $barcodeTouched = false,
        public readonly ?string $barcode = null,
        public readonly bool $internalReferenceTouched = false,
        public readonly ?string $internalReference = null,
        public readonly bool $categoryIdTouched = false,
        public readonly ?string $categoryId = null,
        public readonly bool $subcategoryIdTouched = false,
        public readonly ?string $subcategoryId = null,
        public readonly bool $brandIdTouched = false,
        public readonly ?string $brandId = null,
        public readonly bool $dispensingTypeIdTouched = false,
        public readonly ?string $dispensingTypeId = null,
        public readonly ?string $unitOfMeasure = null,
    ) {
    }
}
