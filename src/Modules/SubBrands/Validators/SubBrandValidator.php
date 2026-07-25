<?php

declare(strict_types=1);

namespace App\Modules\SubBrands\Validators;

use App\Modules\SubBrands\DTOs\CreateSubBrandDTO;
use App\Modules\SubBrands\DTOs\PatchSubBrandDTO;
use InvalidArgumentException;

final class SubBrandValidator
{
    public function validateCreate(array $payload): CreateSubBrandDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $brandId = trim((string) ($payload['brand_id'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;

        if ($brandId === '') {
            throw new InvalidArgumentException('Invalid brand_id');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }

        return new CreateSubBrandDTO($brandId, $name, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchSubBrandDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $brandIdTouched = array_key_exists('brand_id', $payload);
        $brandId = $brandIdTouched ? trim((string) $payload['brand_id']) : null;
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = null;
        }

        if ($brandIdTouched && ($brandId === null || $brandId === '')) {
            throw new InvalidArgumentException('Invalid brand_id');
        }
        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if (array_key_exists('is_active', $payload) && $isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if (!$brandIdTouched && $name === null && $isActive === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchSubBrandDTO($brandId, $brandIdTouched, $name, $isActive);
    }
}
