<?php

namespace App\Modules\Ambientes\Services;

use App\Application\Audit\AuditActor;
use App\Modules\Ambientes\DTOs\CreateAmbienteDTO;
use App\Modules\Ambientes\DTOs\PatchAmbienteDTO;
use App\Modules\Audit\Services\AuditActivityService;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class AmbienteService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditActivityService $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForClinic(string $clinicId, ?bool $active, bool $adminView): array
    {
        $sql = 'SELECT a.id, a.name, a.location, a.device_id, a.is_active, a.created_at, a.updated_at, ca.visible
                FROM ambientes a
                INNER JOIN clinic_ambientes ca ON ca.ambiente_id = a.id AND ca.clinic_id = :clinic_id
                WHERE 1=1';
        $params = ['clinic_id' => $clinicId];
        if (!$adminView) {
            $sql .= ' AND ca.visible = TRUE AND a.is_active = TRUE';
        } elseif ($active !== null) {
            $sql .= ' AND a.is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }
        $sql .= ' ORDER BY a.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentClinicAmbiente($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGlobal(?bool $active): array
    {
        $sql = 'SELECT id, name, location, device_id, is_active, created_at, updated_at FROM ambientes WHERE 1=1';
        $params = [];
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
    public function listWithZonesForClinic(string $clinicId, ?bool $active, bool $adminView): array
    {
        $ambientes = $this->listForClinic($clinicId, $active, $adminView);
        if ($ambientes === []) {
            return [];
        }

        $ambienteIds = array_map(static fn (array $row): string => (string) $row['id'], $ambientes);
        $placeholders = implode(',', array_fill(0, count($ambienteIds), '?'));
        $sql = 'SELECT id, ambiente_id, code, is_active, created_at, updated_at
                FROM zones
                WHERE ambiente_id::text IN (' . $placeholders . ')';
        $params = $ambienteIds;
        if (!$adminView) {
            $sql .= ' AND is_active = TRUE';
        } elseif ($active !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $active ? 'true' : 'false';
        }
        $sql .= ' ORDER BY created_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $zones = $stmt->fetchAll() ?: [];

        $byAmbienteId = [];
        foreach ($zones as $row) {
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
            $ambienteId = (string) ($ambiente['id'] ?? '');
            $ambiente['zones'] = $byAmbienteId[$ambienteId] ?? [];
            $result[] = $ambiente;
        }

        return $result;
    }

    public function getForClinic(string $clinicId, string $ambienteId, bool $adminView): ?array
    {
        $sql = 'SELECT a.id, a.name, a.location, a.device_id, a.is_active, a.created_at, a.updated_at, ca.visible
                FROM ambientes a
                INNER JOIN clinic_ambientes ca ON ca.ambiente_id = a.id AND ca.clinic_id = :clinic_id
                WHERE a.id::text = :id';
        if (!$adminView) {
            $sql .= ' AND ca.visible = TRUE AND a.is_active = TRUE';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $ambienteId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $ambiente = $this->presentClinicAmbiente($row);
        $comp = $this->pdo->prepare(
            'SELECT id, ambiente_id, code, is_active, created_at, updated_at
             FROM zones WHERE ambiente_id::text = :ambiente_id ORDER BY created_at ASC'
        );
        $comp->execute(['ambiente_id' => $ambienteId]);
        $ambiente['zones'] = $comp->fetchAll() ?: [];

        return $ambiente;
    }

    public function getGlobal(string $ambienteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, location, device_id, is_active, created_at, updated_at
             FROM ambientes WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $ambienteId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $comp = $this->pdo->prepare(
            'SELECT id, ambiente_id, code, is_active, created_at, updated_at
             FROM zones WHERE ambiente_id::text = :ambiente_id ORDER BY created_at ASC'
        );
        $comp->execute(['ambiente_id' => $ambienteId]);
        $row['zones'] = $comp->fetchAll() ?: [];

        return $row;
    }

    public function create(CreateAmbienteDTO $dto, AuditActor $actor): array
    {
        $id = Uuid::v4()->toRfc4122();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ambientes (id, name, location, device_id, is_active, created_at, updated_at)
             VALUES (:id, :name, :location, :device_id, :is_active, NOW(), NOW())
             RETURNING id, name, location, device_id, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $dto->name,
            'location' => $dto->location,
            'device_id' => $dto->deviceId,
            'is_active' => $dto->isActive,
        ]);

        $row = (array) $stmt->fetch();
        $presented = $this->presentGlobalAmbiente($row);
        $this->audit->recordAdd('ambiente', $presented['id'], $actor->userId, $actor->clinicId, $presented);

        return $row;
    }

    public function patch(string $ambienteId, PatchAmbienteDTO $dto, AuditActor $actor): ?array
    {
        $current = $this->getGlobal($ambienteId);
        if ($current === null) {
            return null;
        }

        $before = $this->presentGlobalAmbiente($current);

        $deviceId = $current['device_id'];
        if ($dto->deviceIdTouched) {
            $deviceId = $dto->deviceId;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ambientes
             SET name = :name, location = :location, device_id = :device_id, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, name, location, device_id, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $ambienteId,
            'name' => $dto->name ?? $current['name'],
            'location' => $dto->location ?? $current['location'],
            'device_id' => $deviceId,
            'is_active' => $dto->isActive ?? $current['is_active'],
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $after = $this->presentGlobalAmbiente($row);
        $this->audit->recordEdit('ambiente', $ambienteId, $actor->userId, $actor->clinicId, $before, $after);

        return $row;
    }

    public function softDelete(string $ambienteId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ambientes SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $ambienteId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('ambiente', $ambienteId, $actor->userId, $actor->clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    public function associateToClinic(string $clinicId, string $ambienteId, AuditActor $actor): ?array
    {
        $ambiente = $this->getGlobal($ambienteId);
        if ($ambiente === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO clinic_ambientes (clinic_id, ambiente_id, visible)
             VALUES (:clinic_id, :ambiente_id, FALSE)
             ON CONFLICT (clinic_id, ambiente_id) DO NOTHING'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'ambiente_id' => $ambienteId]);

        $result = $this->getForClinic($clinicId, $ambienteId, true);
        if ($result !== null) {
            $this->audit->recordAdd('clinic-ambiente', $ambienteId, $actor->userId, $clinicId, $result);
        }

        return $result;
    }

    public function disassociateFromClinic(string $clinicId, string $ambienteId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM clinic_ambientes WHERE clinic_id::text = :clinic_id AND ambiente_id::text = :ambiente_id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'ambiente_id' => $ambienteId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('clinic-ambiente', $ambienteId, $actor->userId, $clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    public function setClinicVisibility(string $clinicId, string $ambienteId, bool $visible, AuditActor $actor): ?array
    {
        $link = $this->pdo->prepare(
            'SELECT 1 FROM clinic_ambientes WHERE clinic_id::text = :clinic_id AND ambiente_id::text = :ambiente_id LIMIT 1'
        );
        $link->execute(['clinic_id' => $clinicId, 'ambiente_id' => $ambienteId]);
        if (!$link->fetch()) {
            return null;
        }

        $before = $this->getForClinic($clinicId, $ambienteId, true);

        $ambiente = $this->getGlobal($ambienteId);
        if ($ambiente === null || !(bool) $ambiente['is_active']) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE clinic_ambientes SET visible = :visible
             WHERE clinic_id::text = :clinic_id AND ambiente_id::text = :ambiente_id'
        );
        $stmt->bindValue(':clinic_id', $clinicId);
        $stmt->bindValue(':ambiente_id', $ambienteId);
        $stmt->bindValue(':visible', $visible, PDO::PARAM_BOOL);
        $stmt->execute();

        $stmt->execute();

        $after = $this->getForClinic($clinicId, $ambienteId, true);
        if ($after !== null) {
            $this->audit->recordEdit(
                'clinic-ambiente',
                $ambienteId,
                $actor->userId,
                $clinicId,
                $before ?? ['ambiente_id' => $ambienteId, 'clinic_id' => $clinicId, 'visible' => !$visible],
                $after,
            );
        }

        return $after;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentGlobalAmbiente(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'device_id' => $row['device_id'] !== null ? (string) $row['device_id'] : null,
            'is_active' => (bool) ($row['is_active'] ?? true),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentClinicAmbiente(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'device_id' => $row['device_id'] !== null ? (string) $row['device_id'] : null,
            'is_active' => (bool) $row['is_active'],
            'visible' => (bool) ($row['visible'] ?? true),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
