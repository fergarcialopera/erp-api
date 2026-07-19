<?php

declare(strict_types=1);

namespace App\Modules\Brands\Validators;

use App\Modules\Brands\DTOs\CreateBrandDTO;
use App\Modules\Brands\DTOs\PatchBrandDTO;
use InvalidArgumentException;

final class BrandValidator
{
    public function validateCreate(array $payload): CreateBrandDTO
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $slug = null;
        if (array_key_exists('slug', $payload)) {
            $rawSlug = trim((string) $payload['slug']);
            $slug = $rawSlug !== '' ? $rawSlug : null;
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

        return new CreateBrandDTO($name, $slug, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchBrandDTO
    {
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $slugTouched = array_key_exists('slug', $payload);
        $slug = $slugTouched ? trim((string) $payload['slug']) : null;
        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = null;
        }

        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($slugTouched && $slug === '') {
            throw new InvalidArgumentException('Invalid slug');
        }
        if (array_key_exists('is_active', $payload) && $isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if ($name === null && !$slugTouched && $isActive === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchBrandDTO($name, $slugTouched ? $slug : null, $slugTouched, $isActive);
    }
}
