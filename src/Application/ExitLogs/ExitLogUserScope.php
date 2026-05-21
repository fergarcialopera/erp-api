<?php

declare(strict_types=1);

namespace App\Application\ExitLogs;

final class ExitLogUserScope
{
    /**
     * STAFF solo puede ver y modificar salidas creadas por ellos mismos.
     * TECHNICIAN y ADMIN ven todas las de la clínica.
     */
    public static function restrictToCreatorForStaff(array $user): ?string
    {
        if (strtoupper((string) ($user['role'] ?? '')) !== 'STAFF') {
            return null;
        }

        $id = (string) ($user['user_id'] ?? $user['id'] ?? '');

        return $id !== '' ? $id : null;
    }
}
