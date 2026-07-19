<?php

declare(strict_types=1);

namespace App\Modules\Products\DTOs;

final class CreateProductDTO
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isActive,
        public readonly ?string $barcode = null,
        public readonly ?string $internalReference = null,
        public readonly ?string $categoryId = null,
        public readonly ?string $subcategoryId = null,
        public readonly ?string $brandId = null,
        public readonly ?string $dispensingTypeId = null,
        public readonly string $unitOfMeasure = 'Unidades',
    ) {
    }
}
