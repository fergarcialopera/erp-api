<?php

declare(strict_types=1);

namespace App\Modules\Users\DTOs;

final class CreateUserDTO
{
    /**
     * @param list<string> $clinicIds
     */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly string $role,
        public readonly bool $isActive,
        public readonly ?string $pin,
        public readonly ?string $clinicId,
        public readonly array $clinicIds = []
    ) {
    }
}
