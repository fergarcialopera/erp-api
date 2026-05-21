<?php

declare(strict_types=1);

namespace App\Application\ExitLogs\Ports;

interface ExitLogLockPort
{
    /**
     * Load exit log with locker device context for the given clinic.
     * Returns null only if no exit_logs row exists for this id and clinic.
     * Otherwise returns one joined row (LEFT JOINs) for domain policy checks.
     *
     * @return array<string, mixed>|null
     */
    public function findContextForOpenLock(string $clinicId, string $exitLogId, ?string $createdByUserId = null): ?array;

    public function recordLockCommandAttempt(
        string $exitLogId,
        string $clinicId,
        string $deviceId,
        string $topic,
        string $payload,
        string $requestedBy,
        bool $success,
        ?string $errorMessage
    ): void;
}
