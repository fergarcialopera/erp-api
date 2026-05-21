<?php

declare(strict_types=1);

namespace App\Modules\Auth\Mappers;

use App\Application\Support\DisplayName;
use App\Application\Support\PublicUrlBuilder;

final class AuthMapper
{
    public function __construct(private readonly PublicUrlBuilder $urls)
    {
    }

    public function toTokenPayload(array $userRow): array
    {
        return [
            'user_id' => $userRow['id'],
            'clinic_id' => $userRow['clinic_id'],
            'role' => (string) $userRow['role'],
            'email' => (string) $userRow['email'],
        ];
    }

    public function toUserLoginResponse(string $token, array $payload, int $expiresIn): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'user' => [
                'id' => $payload['user_id'],
                'clinic_id' => $payload['clinic_id'],
                'role' => $payload['role'],
                'email' => $payload['email'],
            ],
        ];
    }

    public function toClinicLoginResponse(string $token, array $clinicRow, int $expiresIn): array
    {
        return [
            'clinic_access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'clinic' => $this->toClinicCard($clinicRow),
        ];
    }

    public function toClinicCard(array $row): array
    {
        $name = (string) ($row['name'] ?? '');

        return [
            'id' => (string) $row['id'],
            'name' => $name,
            'image_url' => $this->urls->asset(isset($row['image_path']) ? (string) $row['image_path'] : null),
            'display_initial' => DisplayName::initial($name),
        ];
    }

    public function toStaffCard(array $row): array
    {
        $name = (string) ($row['name'] ?? '');

        return [
            'id' => (string) $row['id'],
            'name' => $name,
            'role' => (string) ($row['role'] ?? ''),
            'image_url' => $this->urls->asset(isset($row['image_path']) ? (string) $row['image_path'] : null),
            'display_initial' => DisplayName::initial($name, (string) ($row['email'] ?? '')),
        ];
    }
}
