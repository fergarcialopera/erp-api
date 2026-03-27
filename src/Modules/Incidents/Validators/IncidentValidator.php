<?php

namespace App\Modules\Incidents\Validators;

use App\Modules\Incidents\DTOs\CreateIncidentDTO;
use InvalidArgumentException;

final class IncidentValidator
{
    private const ALLOWED_SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    public function validateCreate(array $payload): CreateIncidentDTO
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $severity = strtoupper(trim((string) ($payload['severity'] ?? '')));

        if ($title === '') {
            throw new InvalidArgumentException('Invalid title');
        }

        if ($description === '') {
            throw new InvalidArgumentException('Invalid description');
        }

        if (!in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            throw new InvalidArgumentException('Invalid severity');
        }

        return new CreateIncidentDTO($title, $description, $severity);
    }
}
