<?php

namespace App\Modules\Lockers\Services;

use App\Modules\Lockers\DTOs\CreateLockerDTO;
use App\Modules\Lockers\DTOs\PatchLockerDTO;
use PDO;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

final class LockerService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $clinicId, ?bool $active): array
    {
        $sql = 'SELECT public_id AS id, clinic_id, name, location, device_id, is_active, created_at, updated_at
                FROM lockers WHERE clinic_id = :clinic_id';
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

    public function get(string $clinicId, string $publicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT public_id AS id, clinic_id, name, location, device_id, is_active, created_at, updated_at
             FROM lockers WHERE clinic_id = :clinic_id AND public_id = :public_id LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'public_id' => $publicId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $comp = $this->pdo->prepare(
            'SELECT public_id AS id, locker_public_id, code, is_active, created_at, updated_at
             FROM compartments
             WHERE clinic_id = :clinic_id AND locker_public_id = :locker_public_id
             ORDER BY created_at ASC'
        );
        $comp->execute(['clinic_id' => $clinicId, 'locker_public_id' => $publicId]);
        $row['compartments'] = $comp->fetchAll() ?: [];

        return $row;
    }

    public function create(string $clinicId, CreateLockerDTO $dto): array
    {
        $id = Uuid::v4()->toRfc4122();
        $publicId = (string) new Ulid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO lockers (id, public_id, clinic_id, name, location, device_id, is_active, created_at, updated_at)
             VALUES (:id, :public_id, :clinic_id, :name, :location, :device_id, :is_active, NOW(), NOW())
             RETURNING public_id AS id, clinic_id, name, location, device_id, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'public_id' => $publicId,
            'clinic_id' => $clinicId,
            'name' => $dto->name,
            'location' => $dto->location,
            'device_id' => $dto->deviceId,
            'is_active' => $dto->isActive,
        ]);
        return (array) $stmt->fetch();
    }

    public function patch(string $clinicId, string $publicId, PatchLockerDTO $dto): ?array
    {
        $currentStmt = $this->pdo->prepare(
            'SELECT name, location, device_id, is_active FROM lockers WHERE clinic_id = :clinic_id AND public_id = :public_id LIMIT 1'
        );
        $currentStmt->execute(['clinic_id' => $clinicId, 'public_id' => $publicId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            return null;
        }

        $deviceId = $current['device_id'];
        if ($dto->deviceIdTouched) {
            $deviceId = $dto->deviceId;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE lockers
             SET name = :name, location = :location, device_id = :device_id, is_active = :is_active, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND public_id = :public_id
             RETURNING public_id AS id, clinic_id, name, location, device_id, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'public_id' => $publicId,
            'name' => $dto->name ?? $current['name'],
            'location' => $dto->location ?? $current['location'],
            'device_id' => $deviceId,
            'is_active' => $dto->isActive ?? $current['is_active'],
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function softDelete(string $clinicId, string $publicId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE lockers SET is_active = FALSE, updated_at = NOW() WHERE clinic_id = :clinic_id AND public_id = :public_id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'public_id' => $publicId]);
        return $stmt->rowCount() > 0;
    }
}

