<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Support;

final class ExitLogIdParser
{
    /**
     * Acepta id BIGSERIAL (cadena numérica) o UUID según el despliegue.
     */
    public static function parse(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[1-9]\d*$/', $raw) === 1) {
            return $raw;
        }

        $lower = strtolower($raw);
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $lower
        ) === 1) {
            return $lower;
        }

        return null;
    }
}
