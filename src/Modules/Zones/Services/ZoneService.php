<?php

namespace App\Modules\Zones\Services;

use App\Modules\Zones\DTOs\CreateZoneDTO;
use App\Modules\Zones\DTOs\PatchZoneDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class ZoneService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $clinicId, ?string $ambienteId, ?bool $active): array
    {
        $sql = 'SELECT id, clinic_id, ambiente_id, code, is_active, created_at, updated_at
                FROM zones
                WHERE clinic_id = :clinic_id';
        $params = ['clinic_id' => $clinicId];
        if ($ambienteId !== null) {
            $sql .= ' AND ambiente_id = :ambiente_id';
            $params['ambiente_id'] = $ambienteId;
        }
        if ($active !== null) {
            $sql .= ' AND is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }
        $sql .= ' ORDER BY created_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function get(string $clinicId, string $zoneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, ambiente_id, code, is_active, created_at, updated_at
             FROM zones
             WHERE clinic_id = :clinic_id AND id::text = :id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $zoneId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(string $clinicId, CreateZoneDTO $dto): array
    {
        $ambiente = $this->pdo->prepare(
            'SELECT id FROM ambientes WHERE clinic_id = :clinic_id AND id::text = :id LIMIT 1'
        );
        $ambiente->execute(['clinic_id' => $clinicId, 'id' => $dto->ambienteId]);
        if (!$ambiente->fetch()) {
            throw new RuntimeException('Ambiente not found');
        }

        $id = Uuid::v4()->toRfc4122();
        $stmt = $this->pdo->prepare(
            'INSERT INTO zones (id, clinic_id, ambiente_id, code, is_active, created_at, updated_at)
             VALUES (:id, :clinic_id, :ambiente_id, :code, :is_active, NOW(), NOW())
             RETURNING id, clinic_id, ambiente_id, code, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'clinic_id' => $clinicId,
            'ambiente_id' => $dto->ambienteId,
            'code' => $dto->code,
            'is_active' => $dto->isActive,
        ]);
        return (array) $stmt->fetch();
    }

    public function patch(string $clinicId, string $zoneId, PatchZoneDTO $dto): ?array
    {
        $current = $this->get($clinicId, $zoneId);
        if ($current === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE zones
             SET code = :code, is_active = :is_active, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id
             RETURNING id, clinic_id, ambiente_id, code, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'id' => $zoneId,
            'code' => $dto->code ?? $current['code'],
            'is_active' => $dto->isActive ?? $current['is_active'],
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function softDelete(string $clinicId, string $zoneId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE zones SET is_active = FALSE, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $zoneId]);
        return $stmt->rowCount() > 0;
    }
}
