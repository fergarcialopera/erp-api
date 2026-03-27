<?php

namespace App\Modules\Users\DTOs;

final class PatchUserDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $role,
        public readonly ?bool $isActive,
        public readonly ?string $password
    ) {
    }
}

