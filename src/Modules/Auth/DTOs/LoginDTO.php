<?php

namespace App\Modules\Auth\DTOs;

final class LoginDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $password
    ) {
    }
}
