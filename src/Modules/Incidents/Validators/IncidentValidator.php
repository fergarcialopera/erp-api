<?php

namespace App\Modules\Incidents\Validators;

use App\Modules\Incidents\DTOs\CreateIncidentDTO;
use App\Modules\Incidents\DTOs\PatchIncidentDTO;
use InvalidArgumentException;

final class IncidentValidator
{
    private const ALLOWED_SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const ALLOWED_SOURCES = ['ERP', 'AMBIENTE'];
    private const ALLOWED_STATUSES = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];

    public function validateCreate(array $payload): CreateIncidentDTO
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $severity = strtoupper(trim((string) ($payload['severity'] ?? '')));
        $source = strtoupper(trim((string) ($payload['source'] ?? '')));

        if ($title === '') {
            throw new InvalidArgumentException('Invalid title');
        }

        if ($description === '') {
            throw new InvalidArgumentException('Invalid description');
        }

        if (!in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            throw new InvalidArgumentException('Invalid severity');
        }

        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException('Invalid source');
        }

        return new CreateIncidentDTO($title, $description, $severity, $source);
    }

    public function validatePatch(array $payload): PatchIncidentDTO
    {
        $title = array_key_exists('title', $payload) ? trim((string) $payload['title']) : null;
        $description = array_key_exists('description', $payload) ? trim((string) $payload['description']) : null;
        $severity = array_key_exists('severity', $payload) ? strtoupper(trim((string) $payload['severity'])) : null;
        $status = array_key_exists('status', $payload) ? strtoupper(trim((string) $payload['status'])) : null;

        if ($title !== null && $title === '') {
            throw new InvalidArgumentException('Invalid title');
        }
        if ($description !== null && $description === '') {
            throw new InvalidArgumentException('Invalid description');
        }
        if ($severity !== null && !in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            throw new InvalidArgumentException('Invalid severity');
        }
        if ($status !== null && !in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        if ($title === null && $description === null && $severity === null && $status === null) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchIncidentDTO($title, $description, $severity, $status);
    }
}
