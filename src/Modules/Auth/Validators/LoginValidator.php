<?php

namespace App\Modules\Auth\Validators;

use App\Modules\Auth\DTOs\LoginDTO;
use InvalidArgumentException;

final class LoginValidator
{
    public function validate(array $payload): LoginDTO
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }

        if ($password === '') {
            throw new InvalidArgumentException('Invalid password');
        }

        return new LoginDTO($email, $password);
    }
}
