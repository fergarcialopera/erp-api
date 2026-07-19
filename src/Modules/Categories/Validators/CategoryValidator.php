<?php

declare(strict_types=1);

namespace App\Modules\Categories\Validators;

use App\Modules\Categories\DTOs\CreateCategoryDTO;
use App\Modules\Categories\DTOs\PatchCategoryDTO;
use InvalidArgumentException;

final class CategoryValidator
{
    public function validateCreate(array $payload): CreateCategoryDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $description = null;
        if (array_key_exists('description', $payload)) {
            $rawDescription = trim((string) $payload['description']);
            $description = $rawDescription !== '' ? $rawDescription : null;
        }
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }

        return new CreateCategoryDTO($name, $description, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchCategoryDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $descriptionTouched = array_key_exists('description', $payload);
        $description = $descriptionTouched ? trim((string) $payload['description']) : null;
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
        if ($name === null && !$descriptionTouched && $isActive === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchCategoryDTO(
            $name,
            $descriptionTouched ? ($description !== '' ? $description : null) : null,
            $descriptionTouched,
            $isActive
        );
    }
}
