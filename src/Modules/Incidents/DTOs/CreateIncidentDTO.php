<?php

namespace App\Modules\Incidents\DTOs;

final class CreateIncidentDTO
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $severity
    ) {
    }
}
