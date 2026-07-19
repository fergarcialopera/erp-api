<?php

declare(strict_types=1);

namespace App\Modules\Users\Validators;

use App\Application\Support\PinValidator;
use App\Domain\Auth\Role;
use App\Modules\Auth\Services\RecoveryService;
use App\Modules\Users\DTOs\CreateUserDTO;
use App\Modules\Users\DTOs\PatchUserDTO;
use InvalidArgumentException;

final class UserValidator
{
    private const ASSIGNABLE_ROLES = [Role::ADMIN, Role::TECHNICIAN, Role::STAFF];

    public function validateCreate(array $payload): CreateUserDTO
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $role = Role::normalize((string) ($payload['role'] ?? ''));
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;
        $pin = array_key_exists('pin', $payload) ? (string) $payload['pin'] : null;
        $clinicId = array_key_exists('clinic_id', $payload) ? trim((string) $payload['clinic_id']) : null;
        $clinicIds = $this->parseClinicIds($payload);

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('Invalid password');
        }
        if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if ($pin !== null && $pin !== '') {
            PinValidator::assertValid($pin);
        }

        if ($role === Role::ADMIN) {
            if ($clinicIds === [] && ($clinicId === null || $clinicId === '')) {
                throw new InvalidArgumentException('clinic_ids required for ADMIN');
            }
            if ($clinicIds === [] && $clinicId !== null && $clinicId !== '') {
                $clinicIds = [$clinicId];
            }
            $clinicId = $clinicIds[0] ?? null;
        } elseif ($clinicId === null || $clinicId === '') {
            throw new InvalidArgumentException('clinic_id required');
        }

        return new CreateUserDTO(
            $name,
            $email,
            $password,
            $role,
            (bool) $isActive,
            $pin !== '' ? $pin : null,
            $clinicId !== '' ? $clinicId : null,
            $clinicIds,
            $this->parseOperationalRoleId($payload, false)
        );
    }

    public function validatePatch(array $payload): PatchUserDTO
    {
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $role = array_key_exists('role', $payload) ? Role::normalize((string) $payload['role']) : null;
        $password = array_key_exists('password', $payload) ? (string) $payload['password'] : null;
        $pin = array_key_exists('pin', $payload) ? (string) $payload['pin'] : null;
        $clinicIds = array_key_exists('clinic_ids', $payload) ? $this->parseClinicIds($payload) : null;

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
        if ($role !== null && !in_array($role, self::ASSIGNABLE_ROLES, true)) {
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

        if ($name === null && $role === null && $isActive === null && $password === null && ($pin === null || $pin === '') && $unlock === null && $clinicIds === null && !array_key_exists('operational_role_id', $payload)) {
            throw new InvalidArgumentException('No fields to update');
        }

        $operationalRoleIdTouched = array_key_exists('operational_role_id', $payload);
        $operationalRoleId = $operationalRoleIdTouched
            ? $this->parseOperationalRoleId($payload, true)
            : null;

        return new PatchUserDTO(
            $name,
            $role,
            $isActive,
            $password !== '' ? $password : null,
            $pin !== '' ? $pin : null,
            $unlock,
            $clinicIds,
            $operationalRoleIdTouched,
            $operationalRoleId
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

    /**
     * @return list<string>
     */
    private function parseClinicIds(array $payload): array
    {
        if (!array_key_exists('clinic_ids', $payload)) {
            return [];
        }

        $raw = $payload['clinic_ids'];
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Invalid clinic_ids');
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = trim((string) $value);
            if ($id === '') {
                throw new InvalidArgumentException('Invalid clinic_ids');
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseOperationalRoleId(array $payload, bool $allowNullExplicit): ?string
    {
        if (!array_key_exists('operational_role_id', $payload)) {
            return null;
        }
        if ($payload['operational_role_id'] === null || $payload['operational_role_id'] === '') {
            return $allowNullExplicit ? null : null;
        }
        $id = trim((string) $payload['operational_role_id']);
        if ($id === '') {
            return null;
        }

        return $id;
    }
}
