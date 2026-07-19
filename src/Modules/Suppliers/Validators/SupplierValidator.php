<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Validators;

use App\Modules\Suppliers\DTOs\CreateSupplierDTO;
use App\Modules\Suppliers\DTOs\PatchSupplierDTO;
use InvalidArgumentException;

final class SupplierValidator
{
    public function validateCreate(array $payload): CreateSupplierDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $legalName = $this->optionalString($payload, 'legal_name');
        $taxId = $this->optionalString($payload, 'tax_id');
        $email = $this->optionalString($payload, 'email');
        $phone = $this->optionalString($payload, 'phone');
        $isActive = array_key_exists('is_active', $payload)
            ? (is_bool($payload['is_active']) ? $payload['is_active'] : filter_var($payload['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE))
            : true;

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        if ($isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }

        return new CreateSupplierDTO($name, $legalName, $taxId, $email, $phone, (bool) $isActive);
    }

    public function validatePatch(array $payload): PatchSupplierDTO
    {
        if (array_key_exists('slug', $payload)) {
            throw new InvalidArgumentException('slug is not allowed');
        }

        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $legalNameTouched = array_key_exists('legal_name', $payload);
        $legalName = $legalNameTouched ? $this->optionalString($payload, 'legal_name') : null;
        $taxIdTouched = array_key_exists('tax_id', $payload);
        $taxId = $taxIdTouched ? $this->optionalString($payload, 'tax_id') : null;
        $emailTouched = array_key_exists('email', $payload);
        $email = $emailTouched ? $this->optionalString($payload, 'email') : null;
        $phoneTouched = array_key_exists('phone', $payload);
        $phone = $phoneTouched ? $this->optionalString($payload, 'phone') : null;
        if (array_key_exists('is_active', $payload)) {
            $raw = $payload['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $isActive = null;
        }

        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Invalid name');
        }
        if ($emailTouched && $email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        if (array_key_exists('is_active', $payload) && $isActive === null) {
            throw new InvalidArgumentException('Invalid is_active');
        }
        if (
            $name === null && !$legalNameTouched && !$taxIdTouched
            && !$emailTouched && !$phoneTouched && $isActive === null
        ) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchSupplierDTO(
            $name,
            $legalName,
            $legalNameTouched,
            $taxId,
            $taxIdTouched,
            $email,
            $emailTouched,
            $phone,
            $phoneTouched,
            $isActive
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload)) {
            return null;
        }

        $value = trim((string) $payload[$key]);

        return $value !== '' ? $value : null;
    }
}
