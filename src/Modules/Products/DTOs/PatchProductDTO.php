<?php

namespace App\Modules\Products\DTOs;

final class PatchProductDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?bool $isActive
    ) {
    }
}

