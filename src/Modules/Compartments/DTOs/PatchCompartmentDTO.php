<?php

namespace App\Modules\Compartments\DTOs;

final class PatchCompartmentDTO
{
    public function __construct(
        public readonly ?string $code,
        public readonly ?bool $isActive
    ) {
    }
}

