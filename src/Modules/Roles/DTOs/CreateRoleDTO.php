<?php

declare(strict_types=1);

namespace App\Modules\Roles\DTOs;

final class CreateRoleDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $slug,
        public readonly ?string $description,
        public readonly bool $isActive
    ) {
    }
}
