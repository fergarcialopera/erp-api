<?php

declare(strict_types=1);

namespace App\Modules\Auth\Validators;

use App\Modules\Auth\Services\RecoveryService;
use InvalidArgumentException;

final class RecoveryValidator
{
    public function validateClinicRequest(array $payload): string
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }

        return $email;
    }

    public function validateUserRequest(array $payload): array
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $type = trim((string) ($payload['type'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        if (!in_array($type, [RecoveryService::TYPE_USER_PASSWORD, RecoveryService::TYPE_USER_PIN], true)) {
            throw new InvalidArgumentException('Invalid type');
        }

        return ['email' => $email, 'type' => $type];
    }

    public function validateConfirm(array $payload): array
    {
        $token = trim((string) ($payload['token'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));
        $newPassword = array_key_exists('new_password', $payload) ? (string) $payload['new_password'] : null;
        $newPin = array_key_exists('new_pin', $payload) ? (string) $payload['new_pin'] : null;

        if ($token === '') {
            throw new InvalidArgumentException('Invalid token');
        }

        $allowed = [
            RecoveryService::TYPE_CLINIC_PASSWORD,
            RecoveryService::TYPE_USER_PASSWORD,
            RecoveryService::TYPE_USER_PIN,
        ];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Invalid type');
        }

        return [
            'token' => $token,
            'type' => $type,
            'new_password' => $newPassword,
            'new_pin' => $newPin,
        ];
    }
}
