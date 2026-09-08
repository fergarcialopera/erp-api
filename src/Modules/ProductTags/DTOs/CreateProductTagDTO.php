<?php

declare(strict_types=1);

namespace App\Modules\ProductTags\DTOs;

final class CreateProductTagDTO
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isActive
    ) {
    }
}
