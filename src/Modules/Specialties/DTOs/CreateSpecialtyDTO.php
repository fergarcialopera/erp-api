<?php

declare(strict_types=1);

namespace App\Modules\Specialties\DTOs;

final class CreateSpecialtyDTO
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isActive
    ) {
    }
}
