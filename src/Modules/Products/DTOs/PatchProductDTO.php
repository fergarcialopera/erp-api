<?php

declare(strict_types=1);

namespace App\Modules\Products\DTOs;

final class PatchProductDTO
{
    /**
     * @param list<string>|null $tagIds
     */
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
        public readonly bool $nationalCodeTouched = false,
        public readonly ?string $nationalCode = null,
        public readonly bool $packagingTouched = false,
        public readonly ?string $packaging = null,
        public readonly bool $subBrandIdTouched = false,
        public readonly ?string $subBrandId = null,
        public readonly bool $speciesIdTouched = false,
        public readonly ?string $speciesId = null,
        public readonly bool $specialtyIdTouched = false,
        public readonly ?string $specialtyId = null,
        public readonly bool $tagIdsTouched = false,
        public readonly ?array $tagIds = null,
    ) {
    }
}
