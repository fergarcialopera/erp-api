<?php

declare(strict_types=1);

namespace App\Domain\ExitLogs;

final class ExitLogStatus
{
    public const DRAFT = 'DRAFT';

    public const CONFIRMED = 'CONFIRMED';

    public const CANCELLED = 'CANCELLED';

    public static function isDraft(string $status): bool
    {
        return strtoupper(trim($status)) === self::DRAFT;
    }

    public static function isConfirmed(string $status): bool
    {
        return strtoupper(trim($status)) === self::CONFIRMED;
    }

    public static function isCancelled(string $status): bool
    {
        return strtoupper(trim($status)) === self::CANCELLED;
    }
}
