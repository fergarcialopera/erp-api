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
            'clinic_id' => $userRow['clinic_id'] ?? null,
            'role' => (string) $userRow['role'],
            'email' => (string) $userRow['email'],
            'name' => (string) ($userRow['name'] ?? ''),
        ];
    }

    public function toUserLoginResponse(string $token, array $userRow, int $expiresIn): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'user' => [
                'id' => (string) ($userRow['user_id'] ?? $userRow['id'] ?? ''),
                'clinic_id' => isset($userRow['clinic_id']) && $userRow['clinic_id'] !== null
                    ? (string) $userRow['clinic_id']
                    : null,
                'name' => (string) ($userRow['name'] ?? ''),
                'role' => (string) $userRow['role'],
                'email' => (string) $userRow['email'],
                'is_active' => (bool) ($userRow['is_active'] ?? true),
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
