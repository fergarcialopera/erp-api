<?php

declare(strict_types=1);

namespace App\Modules\Clinic\DTOs;

final class PatchClinicDTO
{
    public function __construct(
        public readonly ?bool $visible,
        public readonly ?string $password,
        public readonly ?string $name
    ) {
    }
}
