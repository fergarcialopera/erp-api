<?php

declare(strict_types=1);

namespace App\Modules\Roles\DTOs;

final class PatchRoleDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $slug,
        public readonly bool $slugTouched,
        public readonly ?string $description,
        public readonly bool $descriptionTouched,
        public readonly ?bool $isActive
    ) {
    }
}
