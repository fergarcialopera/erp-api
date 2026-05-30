<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class Env
{
    public static function string(string $key, ?string $default = null): ?string
    {
        $value = self::raw($key);

        return $value ?? $default;
    }

    public static function trimmed(string $key, string $default = ''): string
    {
        return trim(self::string($key, $default) ?? $default);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::raw($key);

        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::raw($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private static function raw(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];

            return $value === false ? null : (string) $value;
        }

        $value = getenv($key);
        if ($value === false) {
            return null;
        }

        return (string) $value;
    }
}
