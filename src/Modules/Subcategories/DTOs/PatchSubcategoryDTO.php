<?php

declare(strict_types=1);

namespace App\Modules\Subcategories\DTOs;

final class PatchSubcategoryDTO
{
    public function __construct(
        public readonly ?string $categoryId,
        public readonly bool $categoryIdTouched,
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly bool $descriptionTouched,
        public readonly ?bool $isActive
    ) {
    }
}
