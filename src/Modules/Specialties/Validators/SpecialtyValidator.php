<?php

declare(strict_types=1);

namespace App\Modules\Specialties\Validators;

use App\Modules\Specialties\DTOs\CreateSpecialtyDTO;
use App\Modules\Specialties\DTOs\PatchSpecialtyDTO;
use InvalidArgumentException;

final class SpecialtyValidator
{
    public function validateCreate(array $payload): CreateSpecialtyDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }

        return new CreateSpecialtyDTO($name, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchSpecialtyDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = null;
        }

        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if (array_key_exists('is_active', $payload) && $isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if ($name === null && $isActive === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchSpecialtyDTO($name, $isActive);
    }
}
