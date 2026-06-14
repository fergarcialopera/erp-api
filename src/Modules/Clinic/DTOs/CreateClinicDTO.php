<?php

declare(strict_types=1);

namespace App\Modules\Clinic\DTOs;

final class CreateClinicDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $password
    ) {
    }
}
