<?php

namespace App\Modules\Settings\Services;

use App\Application\Audit\AuditActor;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Settings\DTOs\UpsertSettingDTO;
use PDO;

final class SettingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditActivityService $audit,
    ) {
    }

    public function list(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, key, value::text AS value, updated_at
             FROM settings
             WHERE clinic_id = :clinic_id
             ORDER BY key ASC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(fn (array $row): array => $this->normalizeValue($row), $rows);
    }

    public function upsert(string $clinicId, UpsertSettingDTO $dto, AuditActor $actor): array
    {
        $existingStmt = $this->pdo->prepare(
            'SELECT id, clinic_id, key, value::text AS value, updated_at
             FROM settings WHERE clinic_id = :clinic_id AND key = :key LIMIT 1'
        );
        $existingStmt->execute(['clinic_id' => $clinicId, 'key' => $dto->key]);
        $existing = $existingStmt->fetch();
        $before = is_array($existing) ? $this->normalizeValue($existing) : null;

        $valueJson = json_encode($dto->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (clinic_id, key, value)
             VALUES (:clinic_id, :key, CAST(:value AS jsonb))
             ON CONFLICT (clinic_id, key)
             DO UPDATE SET
                value = CAST(:value AS jsonb),
                updated_at = NOW()
             RETURNING id, clinic_id, key, value::text AS value, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'key' => $dto->key,
            'value' => $valueJson !== false ? $valueJson : '""',
        ]);

        $setting = $stmt->fetch();
        if (!is_array($setting)) {
            return [];
        }

        $after = $this->normalizeValue($setting);
        $entityId = (string) $after['id'];

        if ($before === null) {
            $this->audit->recordAdd('setting', $entityId, $actor->userId, $clinicId, $after);
        } else {
            $this->audit->recordEdit('setting', $entityId, $actor->userId, $clinicId, $before, $after);
        }

        return $after;
    }

    private function normalizeValue(array $row): array
    {
        if (!array_key_exists('value', $row)) {
            return $row;
        }

        $decoded = json_decode((string) $row['value'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $row['value'] = is_string($decoded) ? $decoded : json_encode($decoded);
        }

        return $row;
    }
}
