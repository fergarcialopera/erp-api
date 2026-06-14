<?php

declare(strict_types=1);

namespace App\Modules\Incidents\DTOs;

final class PatchIncidentDTO
{
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $severity,
        public readonly ?string $status
    ) {
    }
}
