<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Validators;

use App\Modules\Clinic\DTOs\PatchClinicDTO;
use InvalidArgumentException;

final class ClinicValidator
{
    public function validatePatch(array $payload): PatchClinicDTO
    {
        $visible = null;
        if (array_key_exists('visible', $payload)) {
            $raw = $payload['visible'];
            $visible = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($visible === null) {
                throw new InvalidArgumentException('Invalid visible');
            }
        }

        $password = array_key_exists('password', $payload) ? (string) $payload['password'] : null;
        if ($password !== null && $password !== '' && strlen($password) < 6) {
            throw new InvalidArgumentException('Invalid password');
        }

        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Invalid name');
        }

        if ($visible === null && ($password === null || $password === '') && $name === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchClinicDTO(
            $visible,
            $password !== '' ? $password : null,
            $name
        );
    }
}
