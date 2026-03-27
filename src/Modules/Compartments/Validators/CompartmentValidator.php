<?php

namespace App\Modules\Compartments\Validators;

use App\Modules\Compartments\DTOs\CreateCompartmentDTO;
use App\Modules\Compartments\DTOs\PatchCompartmentDTO;
use InvalidArgumentException;

final class CompartmentValidator
{
    public function validateCreate(array $payload): CreateCompartmentDTO
    {
        $lockerId = trim((string) ($payload['locker_id'] ?? ''));
        $code = trim((string) ($payload['code'] ?? ''));
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;

        if ($lockerId === '') {
            throw new InvalidArgumentException('Invalid locker_id');
        }
        if ($code === '') {
            throw new InvalidArgumentException('Invalid code');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }

        return new CreateCompartmentDTO($lockerId, $code, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchCompartmentDTO
    {
        $code = array_key_exists('code', $payload) ? trim((string) $payload['code']) : null;
        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = null;
        }

        if ($code !== null && $code === '') {
            throw new InvalidArgumentException('Invalid code');
        }
        if (array_key_exists('is_active', $payload) && $isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if ($code === null && $isActive === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchCompartmentDTO($code, $isActive);
    }
}

