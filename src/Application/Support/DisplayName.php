<?php

declare(strict_types=1);

namespace App\Application\Support;

final class DisplayName
{
    public static function initial(?string $name, ?string $fallback = null): string
    {
        $source = trim((string) ($name !== null && $name !== '' ? $name : $fallback));

        if ($source === '') {
            return '?';
        }

        if (function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($source, 0, 1));
        }

        return strtoupper(substr($source, 0, 1));
    }
}
