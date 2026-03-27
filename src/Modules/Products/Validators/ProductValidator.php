<?php

namespace App\Modules\Products\Validators;

use App\Modules\Products\DTOs\CreateProductDTO;
use App\Modules\Products\DTOs\PatchProductDTO;
use InvalidArgumentException;

final class ProductValidator
{
    public function validateCreate(array $payload): CreateProductDTO
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = true;
        }

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }

        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }

        return new CreateProductDTO($name, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchProductDTO
    {
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

        return new PatchProductDTO($name, $isActive);
    }
}

