<?php

namespace App\Modules\Lockers\Validators;

use App\Modules\Lockers\DTOs\CreateLockerDTO;
use App\Modules\Lockers\DTOs\PatchLockerDTO;
use InvalidArgumentException;

final class LockerValidator
{
    public function validateCreate(array $payload): CreateLockerDTO
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $location = array_key_exists('location', $payload) ? trim((string) $payload['location']) : null;
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }

        $deviceId = null;
        if (array_key_exists('device_id', $payload)) {
            $rawDevice = $payload['device_id'];
            if ($rawDevice !== null && $rawDevice !== '') {
                $deviceId = trim((string) $rawDevice);
                if ($deviceId === '' || strlen($deviceId) > 128 || !preg_match('/^[A-Za-z0-9._-]+$/', $deviceId)) {
                    throw new InvalidArgumentException('Invalid device_id');
                }
            }
        }

        return new CreateLockerDTO($name, $location !== '' ? $location : null, (bool) $isActive, $deviceId);
    }

    public function validatePatch(array $payload): PatchLockerDTO
    {
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $location = array_key_exists('location', $payload) ? trim((string) $payload['location']) : null;
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
        $deviceIdTouched = array_key_exists('device_id', $payload);
        $deviceId = null;
        if ($deviceIdTouched) {
            $rawDevice = $payload['device_id'];
            if ($rawDevice === null || $rawDevice === '') {
                $deviceId = null;
            } else {
                $deviceId = trim((string) $rawDevice);
                if ($deviceId === '' || strlen($deviceId) > 128 || !preg_match('/^[A-Za-z0-9._-]+$/', $deviceId)) {
                    throw new InvalidArgumentException('Invalid device_id');
                }
            }
        }

        if ($name === null && $location === null && $isActive === null && !$deviceIdTouched) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchLockerDTO($name, $location !== '' ? $location : $location, $isActive, $deviceIdTouched, $deviceId);
    }
}

