<?php

declare(strict_types=1);

namespace App\Modules\Brands\DTOs;

final class CreateBrandDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $slug,
        public readonly bool $isActive
    ) {
    }
}
