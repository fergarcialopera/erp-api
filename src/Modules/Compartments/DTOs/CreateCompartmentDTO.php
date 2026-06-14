<?php

namespace App\Modules\Compartments\DTOs;

final class CreateCompartmentDTO
{
    public function __construct(
        public readonly string $ambienteId,
        public readonly string $code,
        public readonly bool $isActive
    ) {
    }
}

