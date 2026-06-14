<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\ExitLogs\Ports\ExitLogLockPort;
use PDO;

final class PdoExitLogLockPort implements ExitLogLockPort
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findContextForOpenLock(string $clinicId, string $exitLogId, ?string $createdByUserId = null): ?array
    {
        $creatorFilter = $createdByUserId !== null
            ? ' AND el.created_by_user_id::text = :created_by_user_id'
            : '';

        $sql = <<<SQL
WITH resolved AS (
    SELECT
        el.id AS exit_log_id,
        el.status,
        COALESCE(
            el.zone_id,
            (
                SELECT ei.zone_id
                FROM exit_log_items ei
                WHERE ei.exit_log_id = el.id
                  AND ei.confirmed_quantity IS NOT NULL
                  AND ei.confirmed_quantity > 0
                  AND ei.zone_id IS NOT NULL
                ORDER BY ei.id ASC
                LIMIT 1
            )
        ) AS zone_id
    FROM exit_logs el
    WHERE el.id::text = :id AND el.clinic_id = CAST(:clinic_id AS UUID){$creatorFilter}
    LIMIT 1
)
SELECT
    r.exit_log_id,
    r.status,
    r.zone_id,
    (c.id IS NOT NULL) AS zone_resolved,
    COALESCE(c.is_active, FALSE) AS zone_is_active,
    (a.id IS NOT NULL) AS ambiente_resolved,
    COALESCE(a.is_active, FALSE) AS ambiente_is_active,
    a.device_id
FROM resolved r
LEFT JOIN zones c
    ON c.id = r.zone_id
LEFT JOIN ambientes a
    ON a.id = c.ambiente_id
LEFT JOIN clinic_ambientes ca
    ON ca.ambiente_id = a.id AND ca.clinic_id = :clinic_id
SQL;

        $stmt = $this->pdo->prepare($sql);
        $params = [
            'id' => $exitLogId,
            'clinic_id' => $clinicId,
        ];
        if ($createdByUserId !== null) {
            $params['created_by_user_id'] = $createdByUserId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function recordLockCommandAttempt(
        string $exitLogId,
        string $clinicId,
        string $deviceId,
        string $topic,
        string $payload,
        string $requestedBy,
        bool $success,
        ?string $errorMessage
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO exit_log_lock_commands
                (exit_log_id, clinic_id, device_id, topic, payload, requested_by_user_id, success, error_message)
             VALUES
                (:exit_log_id, :clinic_id, :device_id, :topic, :payload, :requested_by_user_id, :success, :error_message)'
        );
        $stmt->bindValue(':exit_log_id', $exitLogId);
        $stmt->bindValue(':clinic_id', $clinicId);
        $stmt->bindValue(':device_id', $deviceId);
        $stmt->bindValue(':topic', $topic);
        $stmt->bindValue(':payload', $payload);
        $stmt->bindValue(':requested_by_user_id', $requestedBy);
        $stmt->bindValue(':success', $success, PDO::PARAM_BOOL);
        if ($errorMessage === null) {
            $stmt->bindValue(':error_message', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':error_message', $errorMessage);
        }
        $stmt->execute();
    }
}
