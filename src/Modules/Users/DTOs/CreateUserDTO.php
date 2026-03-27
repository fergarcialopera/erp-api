<?php

namespace App\Modules\Users\DTOs;

final class CreateUserDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly string $role,
        public readonly bool $isActive
    ) {
    }
}

