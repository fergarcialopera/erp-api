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

    /**
     * @return list<array<string, mixed>>
     */
    public function listForClinic(string $clinicId, ?string $ambienteId, ?bool $active): array
    {
        $sql = 'SELECT z.id, z.ambiente_id, z.code, z.is_active, z.created_at, z.updated_at
                FROM zones z
                INNER JOIN clinic_ambientes ca ON ca.ambiente_id = z.ambiente_id AND ca.clinic_id = :clinic_id
                INNER JOIN ambientes a ON a.id = z.ambiente_id
                WHERE ca.visible = TRUE AND a.is_active = TRUE';
        $params = ['clinic_id' => $clinicId];
        if ($ambienteId !== null) {
            $sql .= ' AND z.ambiente_id::text = :ambiente_id';
            $params['ambiente_id'] = $ambienteId;
        }
        if ($active !== null) {
            $sql .= ' AND z.is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }
        $sql .= ' ORDER BY z.created_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGlobal(?string $ambienteId, ?bool $active): array
    {
        $sql = 'SELECT id, ambiente_id, code, is_active, created_at, updated_at FROM zones WHERE 1=1';
        $params = [];
        if ($ambienteId !== null) {
            $sql .= ' AND ambiente_id::text = :ambiente_id';
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

    public function getForClinic(string $clinicId, string $zoneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT z.id, z.ambiente_id, z.code, z.is_active, z.created_at, z.updated_at
             FROM zones z
             INNER JOIN clinic_ambientes ca ON ca.ambiente_id = z.ambiente_id AND ca.clinic_id = :clinic_id
             WHERE z.id::text = :id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $zoneId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function getGlobal(string $zoneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ambiente_id, code, is_active, created_at, updated_at
             FROM zones WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $zoneId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function create(CreateZoneDTO $dto): array
    {
        $ambiente = $this->pdo->prepare('SELECT id FROM ambientes WHERE id::text = :id LIMIT 1');
        $ambiente->execute(['id' => $dto->ambienteId]);
        if (!$ambiente->fetch()) {
            throw new RuntimeException('Ambiente not found');
        }

        $id = Uuid::v4()->toRfc4122();
        $stmt = $this->pdo->prepare(
            'INSERT INTO zones (id, ambiente_id, code, is_active, created_at, updated_at)
             VALUES (:id, :ambiente_id, :code, :is_active, NOW(), NOW())
             RETURNING id, ambiente_id, code, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'ambiente_id' => $dto->ambienteId,
            'code' => $dto->code,
            'is_active' => $dto->isActive,
        ]);

        return (array) $stmt->fetch();
    }

    public function patch(string $zoneId, PatchZoneDTO $dto): ?array
    {
        $current = $this->getGlobal($zoneId);
        if ($current === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE zones
             SET code = :code, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, ambiente_id, code, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $zoneId,
            'code' => $dto->code ?? $current['code'],
            'is_active' => $dto->isActive ?? $current['is_active'],
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function softDelete(string $zoneId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE zones SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $zoneId]);

        return $stmt->rowCount() > 0;
    }
}
