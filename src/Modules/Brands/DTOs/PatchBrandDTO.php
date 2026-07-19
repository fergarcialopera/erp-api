<?php

declare(strict_types=1);

namespace App\Modules\Brands\DTOs;

final class PatchBrandDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?bool $isActive
    ) {
    }
}
