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

    public function findContextForOpenLock(string $clinicId, string $exitLogId): ?array
    {
        $sql = <<<'SQL'
WITH resolved AS (
    SELECT
        el.id AS exit_log_id,
        el.status,
        COALESCE(
            NULLIF(TRIM(el.compartment_public_id), ''),
            (
                SELECT ei.compartment_public_id
                FROM exit_log_items ei
                WHERE ei.exit_log_id = el.id
                  AND ei.confirmed_quantity IS NOT NULL
                  AND ei.confirmed_quantity > 0
                  AND ei.compartment_public_id IS NOT NULL
                ORDER BY ei.id ASC
                LIMIT 1
            )
        ) AS compartment_public_id
    FROM exit_logs el
    WHERE el.id::text = :id AND el.clinic_id = CAST(:clinic_id AS UUID)
    LIMIT 1
)
SELECT
    r.exit_log_id,
    r.status,
    r.compartment_public_id,
    (c.public_id IS NOT NULL) AS compartment_resolved,
    COALESCE(c.is_active, FALSE) AS compartment_is_active,
    (l.public_id IS NOT NULL) AS locker_resolved,
    COALESCE(l.is_active, FALSE) AS locker_is_active,
    l.device_id
FROM resolved r
LEFT JOIN compartments c
    ON c.public_id = r.compartment_public_id AND c.clinic_id = :clinic_id
LEFT JOIN lockers l
    ON l.public_id = c.locker_public_id AND l.clinic_id = :clinic_id
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $exitLogId,
            'clinic_id' => $clinicId,
        ]);
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
                (exit_log_id, clinic_id, device_id, topic, payload, requested_by, success, error_message)
             VALUES
                (:exit_log_id, :clinic_id, :device_id, :topic, :payload, :requested_by, :success, :error_message)'
        );
        $stmt->bindValue(':exit_log_id', $exitLogId);
        $stmt->bindValue(':clinic_id', $clinicId);
        $stmt->bindValue(':device_id', $deviceId);
        $stmt->bindValue(':topic', $topic);
        $stmt->bindValue(':payload', $payload);
        $stmt->bindValue(':requested_by', $requestedBy);
        $stmt->bindValue(':success', $success, PDO::PARAM_BOOL);
        if ($errorMessage === null) {
            $stmt->bindValue(':error_message', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':error_message', $errorMessage);
        }
        $stmt->execute();
    }
}
