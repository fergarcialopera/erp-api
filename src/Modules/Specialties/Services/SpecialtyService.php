<?php

declare(strict_types=1);

namespace App\Modules\Specialties\Services;

use App\Application\Audit\AuditActor;
use App\Application\Support\Slug;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Specialties\DTOs\CreateSpecialtyDTO;
use App\Modules\Specialties\DTOs\PatchSpecialtyDTO;
use PDO;
use Symfony\Component\Uid\Uuid;

final class SpecialtyService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditActivityService $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?bool $active): array
    {
        $sql = 'SELECT id, name, slug, is_active, created_at, updated_at FROM specialties WHERE 1=1';
        $params = [];
        if ($active !== null) {
            $sql .= ' AND is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    public function get(string $specialtyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, is_active, created_at, updated_at
             FROM specialties WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $specialtyId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateSpecialtyDTO $dto, AuditActor $actor): array
    {
        $id = Uuid::v4()->toRfc4122();
        $slug = Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO specialties (id, name, slug, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :is_active, NOW(), NOW())
             RETURNING id, name, slug, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        $presented = $this->present((array) $stmt->fetch());
        $this->audit->recordAdd('specialty', $presented['id'], $actor->userId, $actor->clinicId, $presented);

        return $presented;
    }

    public function patch(string $specialtyId, PatchSpecialtyDTO $dto, AuditActor $actor): ?array
    {
        $before = $this->get($specialtyId);
        if ($before === null) {
            return null;
        }

        $name = $dto->name ?? (string) $before['name'];
        $slug = $dto->name !== null ? Slug::from($name) : (string) $before['slug'];

        $stmt = $this->pdo->prepare(
            'UPDATE specialties
             SET name = :name, slug = :slug, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, name, slug, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $specialtyId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_active', $dto->isActive ?? (bool) $before['is_active'], PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $after = $this->present($row);
        $this->audit->recordEdit('specialty', $specialtyId, $actor->userId, $actor->clinicId, $before, $after);

        return $after;
    }

    public function softDelete(string $specialtyId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE specialties SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $specialtyId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('specialty', $specialtyId, $actor->userId, $actor->clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
