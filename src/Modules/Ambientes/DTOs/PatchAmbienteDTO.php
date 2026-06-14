<?php

namespace App\Modules\Ambientes\DTOs;

final class PatchAmbienteDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $location,
        public readonly ?bool $isActive,
        public readonly bool $deviceIdTouched,
        public readonly ?string $deviceId
    ) {
    }
}

