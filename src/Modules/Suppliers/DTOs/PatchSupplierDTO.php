<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\DTOs;

final class PatchSupplierDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $legalName,
        public readonly bool $legalNameTouched,
        public readonly ?string $taxId,
        public readonly bool $taxIdTouched,
        public readonly ?string $email,
        public readonly bool $emailTouched,
        public readonly ?string $phone,
        public readonly bool $phoneTouched,
        public readonly ?bool $isActive
    ) {
    }
}
