<?php

declare(strict_types=1);

namespace App\Application\Support;

use InvalidArgumentException;

final class PinValidator
{
    public static function assertValid(string $pin): void
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new InvalidArgumentException('PIN must be 4 to 6 numeric digits');
        }
    }
}
