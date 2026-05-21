<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use RuntimeException;

final class PinLockedException extends RuntimeException
{
    public function __construct(
        public readonly int $failedAttempts,
        string $message = 'PIN locked. Use classic login.'
    ) {
        parent::__construct($message);
    }
}
