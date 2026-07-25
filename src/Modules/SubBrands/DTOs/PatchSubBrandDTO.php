<?php

declare(strict_types=1);

namespace App\Modules\SubBrands\DTOs;

final class PatchSubBrandDTO
{
    public function __construct(
        public readonly ?string $brandId,
        public readonly bool $brandIdTouched,
        public readonly ?string $name,
        public readonly ?bool $isActive
    ) {
    }
}
