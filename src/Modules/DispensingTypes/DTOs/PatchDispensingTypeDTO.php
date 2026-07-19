<?php

declare(strict_types=1);

namespace App\Modules\DispensingTypes\DTOs;

final class PatchDispensingTypeDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly bool $descriptionTouched,
        public readonly ?bool $isActive
    ) {
    }
}
