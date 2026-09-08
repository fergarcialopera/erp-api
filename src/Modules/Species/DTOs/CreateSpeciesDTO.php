<?php

declare(strict_types=1);

namespace App\Modules\Species\DTOs;

final class CreateSpeciesDTO
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isActive
    ) {
    }
}
