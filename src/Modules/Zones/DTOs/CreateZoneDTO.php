<?php

namespace App\Modules\Zones\DTOs;

final class CreateZoneDTO
{
    public function __construct(
        public readonly string $ambienteId,
        public readonly string $code,
        public readonly bool $isActive
    ) {
    }
}

