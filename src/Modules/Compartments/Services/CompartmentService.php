<?php

namespace App\Modules\Compartments\Services;

use App\Modules\Compartments\DTOs\CreateCompartmentDTO;
use App\Modules\Compartments\DTOs\PatchCompartmentDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class CompartmentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $clinicId, ?string $lockerId, ?bool $active): array
    {
        $sql = 'SELECT id, clinic_id, locker_id, code, is_active, created_at, updated_at
                FROM compartments
                WHERE clinic_id = :clinic_id';
        $params = ['clinic_id' => $clinicId];
        if ($lockerId !== null) {
            $sql .= ' AND locker_id = :locker_id';
            $params['locker_id'] = $lockerId;
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

    public function get(string $clinicId, string $compartmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, locker_id, code, is_active, created_at, updated_at
             FROM compartments
             WHERE clinic_id = :clinic_id AND id::text = :id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $compartmentId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(string $clinicId, CreateCompartmentDTO $dto): array
    {
        $locker = $this->pdo->prepare(
            'SELECT id FROM lockers WHERE clinic_id = :clinic_id AND id::text = :id LIMIT 1'
        );
        $locker->execute(['clinic_id' => $clinicId, 'id' => $dto->lockerId]);
        if (!$locker->fetch()) {
            throw new RuntimeException('Locker not found');
        }

        $id = Uuid::v4()->toRfc4122();
        $stmt = $this->pdo->prepare(
            'INSERT INTO compartments (id, clinic_id, locker_id, code, is_active, created_at, updated_at)
             VALUES (:id, :clinic_id, :locker_id, :code, :is_active, NOW(), NOW())
             RETURNING id, clinic_id, locker_id, code, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'clinic_id' => $clinicId,
            'locker_id' => $dto->lockerId,
            'code' => $dto->code,
            'is_active' => $dto->isActive,
        ]);
        return (array) $stmt->fetch();
    }

    public function patch(string $clinicId, string $compartmentId, PatchCompartmentDTO $dto): ?array
    {
        $current = $this->get($clinicId, $compartmentId);
        if ($current === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE compartments
             SET code = :code, is_active = :is_active, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id
             RETURNING id, clinic_id, locker_id, code, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'id' => $compartmentId,
            'code' => $dto->code ?? $current['code'],
            'is_active' => $dto->isActive ?? $current['is_active'],
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function softDelete(string $clinicId, string $compartmentId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE compartments SET is_active = FALSE, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $compartmentId]);
        return $stmt->rowCount() > 0;
    }
}

