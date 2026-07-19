<?php

declare(strict_types=1);

namespace App\Application\Support;

final class Slug
{
    /**
     * Normaliza un nombre a slug comparable (minúsculas, sin tildes, guiones).
     * Pensado también para futuras importaciones CSV.
     */
    public static function from(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = $value;
        }

        // iconv puede dejar comillas/acentos residuales (p. ej. Í → 'I).
        $ascii = str_replace(["'", '`', '"', '^', '~'], '', $ascii);
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '';
        $ascii = trim($ascii, '-');

        return $ascii !== '' ? $ascii : 'n-a';
    }
}
