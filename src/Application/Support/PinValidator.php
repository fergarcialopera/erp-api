<?php

declare(strict_types=1);

namespace App\Application\Support;

use InvalidArgumentException;

final class PinValidator
{
    public static function assertValid(string $pin): void
    {
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new InvalidArgumentException('PIN must be exactly 4 numeric digits');
        }
    }
}
