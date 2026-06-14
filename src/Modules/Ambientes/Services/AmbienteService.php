<?php

namespace App\Modules\Ambientes\Services;

use App\Modules\Ambientes\DTOs\CreateAmbienteDTO;
use App\Modules\Ambientes\DTOs\PatchAmbienteDTO;
use PDO;
use Symfony\Component\Uid\Uuid;

final class AmbienteService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $clinicId, ?bool $active): array
    {
        $sql = 'SELECT id, clinic_id, name, location, device_id, is_active, created_at, updated_at
                FROM ambientes WHERE clinic_id = :clinic_id';
        $params = ['clinic_id' => $clinicId];
        if ($active !== null) {
            $sql .= ' AND is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWithCompartments(string $clinicId, ?bool $active): array
    {
        $ambientes = $this->list($clinicId, $active);
        if ($ambientes === []) {
            return [];
        }

        $sql = 'SELECT id, ambiente_id, code, is_active, created_at, updated_at
                FROM compartments
                WHERE clinic_id = :clinic_id';
        $params = ['clinic_id' => $clinicId];
        if ($active !== null) {
            $sql .= ' AND is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }
        $sql .= ' ORDER BY created_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $compartments = $stmt->fetchAll() ?: [];

        $byAmbienteId = [];
        foreach ($compartments as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ambienteId = (string) ($row['ambiente_id'] ?? '');
            if ($ambienteId === '') {
                continue;
            }
            $byAmbienteId[$ambienteId][] = $row;
        }

        $result = [];
        foreach ($ambientes as $ambiente) {
            if (!is_array($ambiente)) {
                continue;
            }
            $ambienteId = (string) ($ambiente['id'] ?? '');
            $ambiente['compartments'] = $byAmbienteId[$ambienteId] ?? [];
            $result[] = $ambiente;
        }

        return $result;
    }

    public function get(string $clinicId, string $ambienteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, location, device_id, is_active, created_at, updated_at
             FROM ambientes WHERE clinic_id = :clinic_id AND id::text = :id LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $ambienteId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $comp = $this->pdo->prepare(
            'SELECT id, ambiente_id, code, is_active, created_at, updated_at
             FROM compartments
             WHERE clinic_id = :clinic_id AND ambiente_id = :ambiente_id
             ORDER BY created_at ASC'
        );
        $comp->execute(['clinic_id' => $clinicId, 'ambiente_id' => $ambienteId]);
        $row['compartments'] = $comp->fetchAll() ?: [];

        return $row;
    }

    public function create(string $clinicId, CreateAmbienteDTO $dto): array
    {
        $id = Uuid::v4()->toRfc4122();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ambientes (id, clinic_id, name, location, device_id, is_active, created_at, updated_at)
             VALUES (:id, :clinic_id, :name, :location, :device_id, :is_active, NOW(), NOW())
             RETURNING id, clinic_id, name, location, device_id, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'clinic_id' => $clinicId,
            'name' => $dto->name,
            'location' => $dto->location,
            'device_id' => $dto->deviceId,
            'is_active' => $dto->isActive,
        ]);
        return (array) $stmt->fetch();
    }

    public function patch(string $clinicId, string $ambienteId, PatchAmbienteDTO $dto): ?array
    {
        $currentStmt = $this->pdo->prepare(
            'SELECT name, location, device_id, is_active FROM ambientes WHERE clinic_id = :clinic_id AND id::text = :id LIMIT 1'
        );
        $currentStmt->execute(['clinic_id' => $clinicId, 'id' => $ambienteId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            return null;
        }

        $deviceId = $current['device_id'];
        if ($dto->deviceIdTouched) {
            $deviceId = $dto->deviceId;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ambientes
             SET name = :name, location = :location, device_id = :device_id, is_active = :is_active, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id
             RETURNING id, clinic_id, name, location, device_id, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'id' => $ambienteId,
            'name' => $dto->name ?? $current['name'],
            'location' => $dto->location ?? $current['location'],
            'device_id' => $deviceId,
            'is_active' => $dto->isActive ?? $current['is_active'],
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function softDelete(string $clinicId, string $ambienteId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ambientes SET is_active = FALSE, updated_at = NOW() WHERE clinic_id = :clinic_id AND id::text = :id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $ambienteId]);
        return $stmt->rowCount() > 0;
    }
}

