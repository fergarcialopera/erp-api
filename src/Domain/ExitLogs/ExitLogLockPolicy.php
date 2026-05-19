<?php

declare(strict_types=1);

namespace App\Domain\ExitLogs;

use App\Domain\ExitLogs\Exception\ExitLogLockDeniedException;

final class ExitLogLockPolicy
{
    /**
     * @param array<string, mixed> $context Row from ExitLogLockPort::findContextForOpenLock
     */
    public static function assertCanOpenLock(array $context): void
    {
        $status = strtoupper(trim((string) ($context['status'] ?? '')));
        if ($status !== ExitLogStatus::CONFIRMED) {
            throw new ExitLogLockDeniedException(
                'The lock can only be opened after the exit log has been confirmed.'
            );
        }

        $compartmentId = $context['compartment_id'] ?? null;
        if ($compartmentId === null || $compartmentId === '') {
            throw new ExitLogLockDeniedException('Exit log has no compartment linked; lock cannot be opened.');
        }

        if (!self::isPostgresTruthy($context['compartment_resolved'] ?? false)) {
            throw new ExitLogLockDeniedException('Linked compartment is missing or not in this clinic; lock cannot be opened.');
        }

        if (!self::isPostgresTruthy($context['compartment_is_active'] ?? false)) {
            throw new ExitLogLockDeniedException('Compartment is inactive; lock cannot be opened.');
        }

        if (!self::isPostgresTruthy($context['locker_resolved'] ?? false)) {
            throw new ExitLogLockDeniedException('Locker for this compartment is missing; lock cannot be opened.');
        }

        if (!self::isPostgresTruthy($context['locker_is_active'] ?? false)) {
            throw new ExitLogLockDeniedException('Locker is inactive; lock cannot be opened.');
        }

        $deviceId = isset($context['device_id']) ? trim((string) $context['device_id']) : '';
        if ($deviceId === '') {
            throw new ExitLogLockDeniedException('Locker has no device configured; lock cannot be opened.');
        }
    }

    private static function isPostgresTruthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true'
            || $value === 'on'
            || $value === 'yes';
    }
}
