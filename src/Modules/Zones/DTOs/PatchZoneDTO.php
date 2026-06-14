<?php

namespace App\Modules\Zones\DTOs;

final class PatchZoneDTO
{
    public function __construct(
        public readonly ?string $code,
        public readonly ?bool $isActive
    ) {
    }
}

