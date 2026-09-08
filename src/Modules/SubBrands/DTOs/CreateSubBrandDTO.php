<?php

declare(strict_types=1);

namespace App\Modules\SubBrands\DTOs;

final class CreateSubBrandDTO
{
    public function __construct(
        public readonly string $brandId,
        public readonly string $name,
        public readonly bool $isActive
    ) {
    }
}
