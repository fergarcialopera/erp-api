<?php

namespace App\Modules\Users\Validators;

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

        return new CreateUserDTO($name, $email, $password, $role, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchUserDTO
    {
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $role = array_key_exists('role', $payload) ? strtoupper(trim((string) $payload['role'])) : null;
        $password = array_key_exists('password', $payload) ? (string) $payload['password'] : null;

        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = null;
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
        if ($name === null && $role === null && $isActive === null && $password === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchUserDTO($name, $role, $isActive, $password !== '' ? $password : null);
    }
}

