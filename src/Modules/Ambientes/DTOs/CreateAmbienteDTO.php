<?php

namespace App\Modules\Ambientes\DTOs;

final class CreateAmbienteDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $location,
        public readonly bool $isActive,
        public readonly ?string $deviceId
    ) {
    }
}

