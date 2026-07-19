<?php

declare(strict_types=1);

namespace App\Modules\Users\DTOs;

final class PatchUserDTO
{
    /**
     * @param list<string>|null $clinicIds
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $role,
        public readonly ?bool $isActive,
        public readonly ?string $password,
        public readonly ?string $pin,
        public readonly ?bool $unlock,
        public readonly ?array $clinicIds = null,
        public readonly bool $operationalRoleIdTouched = false,
        public readonly ?string $operationalRoleId = null,
    ) {
    }
}
