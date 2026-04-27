<?php

namespace App\Modules\Lockers\DTOs;

final class CreateLockerDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $location,
        public readonly bool $isActive,
        public readonly ?string $deviceId
    ) {
    }
}

