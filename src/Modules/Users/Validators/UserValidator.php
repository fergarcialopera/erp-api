<?php

declare(strict_types=1);

namespace App\Modules\Users\Validators;

use App\Application\Support\PinValidator;
use App\Modules\Auth\Services\RecoveryService;
use App\Modules\Users\DTOs\CreateUserDTO;
use App\Modules\Users\DTOs\PatchUserDTO;
use InvalidArgumentException;

final class UserValidator
{
    private const ALLOWED_ROLES = ['ADMIN', 'TECHNICIAN', 'STAFF'];

    public function validateCreate(array $payload): CreateUserDTO
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $role = strtoupper(trim((string) ($payload['role'] ?? '')));
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;
        $pin = array_key_exists('pin', $payload) ? (string) $payload['pin'] : null;

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('Invalid password');
        }
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if ($pin !== null && $pin !== '') {
            PinValidator::assertValid($pin);
        }

        return new CreateUserDTO($name, $email, $password, $role, (bool) $isActive, $pin !== '' ? $pin : null);
    }

    public function validatePatch(array $payload): PatchUserDTO
    {
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $role = array_key_exists('role', $payload) ? strtoupper(trim((string) $payload['role'])) : null;
        $password = array_key_exists('password', $payload) ? (string) $payload['password'] : null;
        $pin = array_key_exists('pin', $payload) ? (string) $payload['pin'] : null;

        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = null;
        }

        $unlock = null;
        if (array_key_exists('unlock', $payload)) {
            $rawUnlock = $payload['unlock'];
            $unlock = is_bool($rawUnlock) ? $rawUnlock : filter_var($rawUnlock, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($role !== null && !in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }
        if (array_key_exists('is_active', $payload) && $isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if ($password !== null && $password !== '' && strlen($password) < 6) {
            throw new InvalidArgumentException('Invalid password');
        }
        if ($pin !== null && $pin !== '') {
            PinValidator::assertValid($pin);
        }
        if (array_key_exists('unlock', $payload) && $unlock === null) {
            throw new InvalidArgumentException('Invalid unlock');
        }

        if ($name === null && $role === null && $isActive === null && $password === null && ($pin === null || $pin === '') && $unlock === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchUserDTO(
            $name,
            $role,
            $isActive,
            $password !== '' ? $password : null,
            $pin !== '' ? $pin : null,
            $unlock
        );
    }

    public function validateRecoveryRequest(array $payload): string
    {
        $type = trim((string) ($payload['type'] ?? ''));
        if (!in_array($type, [RecoveryService::TYPE_USER_PASSWORD, RecoveryService::TYPE_USER_PIN], true)) {
            throw new InvalidArgumentException('Invalid type');
        }

        return $type;
    }
}
