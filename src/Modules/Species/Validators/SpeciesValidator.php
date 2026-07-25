<?php

declare(strict_types=1);

namespace App\Modules\Species\Validators;

use App\Modules\Species\DTOs\CreateSpeciesDTO;
use App\Modules\Species\DTOs\PatchSpeciesDTO;
use InvalidArgumentException;

final class SpeciesValidator
{
    public function validateCreate(array $payload): CreateSpeciesDTO
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

        return new CreateSpeciesDTO($name, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchSpeciesDTO
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

        return new PatchSpeciesDTO($name, $isActive);
    }
}
