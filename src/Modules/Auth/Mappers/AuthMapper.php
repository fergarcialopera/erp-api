<?php

namespace App\Modules\Auth\Mappers;

use App\Modules\Auth\DTOs\LoginDTO;

final class AuthMapper
{
    public function toTokenPayload(array $userRow): array
    {
        return [
            'user_id' => $userRow['id'],
            'clinic_id' => $userRow['clinic_id'],
            'role' => (string) $userRow['role'],
            'email' => (string) $userRow['email'],
        ];
    }

    public function toLoginResponse(string $token, array $payload): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 1800,
            'user' => [
                'id' => $payload['user_id'],
                'clinic_id' => $payload['clinic_id'],
                'role' => $payload['role'],
                'email' => $payload['email'],
            ],
        ];
    }
}
