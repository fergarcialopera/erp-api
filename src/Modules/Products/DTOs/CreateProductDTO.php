<?php

declare(strict_types=1);

namespace App\Modules\Products\DTOs;

final class CreateProductDTO
{
    /**
     * @param list<string>|null $tagIds
     */
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
        public readonly ?string $nationalCode = null,
        public readonly ?string $packaging = null,
        public readonly ?string $subBrandId = null,
        public readonly ?string $speciesId = null,
        public readonly ?string $specialtyId = null,
        public readonly ?array $tagIds = null,
    ) {
    }
}
