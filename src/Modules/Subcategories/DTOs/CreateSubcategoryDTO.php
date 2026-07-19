<?php

declare(strict_types=1);

namespace App\Modules\Subcategories\DTOs;

final class CreateSubcategoryDTO
{
    public function __construct(
        public readonly string $categoryId,
        public readonly string $name,
        public readonly ?string $slug,
        public readonly ?string $description,
        public readonly bool $isActive
    ) {
    }
}
