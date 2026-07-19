<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\DTOs;

final class CreateSupplierDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $legalName,
        public readonly ?string $taxId,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly bool $isActive
    ) {
    }
}
