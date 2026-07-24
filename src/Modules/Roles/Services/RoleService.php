<?php

declare(strict_types=1);

namespace App\Modules\Roles\Services;

use App\Application\Audit\AuditActor;
use App\Application\Support\Slug;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Roles\DTOs\CreateRoleDTO;
use App\Modules\Roles\DTOs\PatchRoleDTO;
use PDO;
use Symfony\Component\Uid\Uuid;

final class RoleService
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
        $sql = 'SELECT id, name, slug, description, is_active, created_at, updated_at FROM roles WHERE 1=1';
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

    public function get(string $roleId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, description, is_active, created_at, updated_at
             FROM roles WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $roleId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateRoleDTO $dto, AuditActor $actor): array
    {
        $id = Uuid::v4()->toRfc4122();
        $slug = Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (id, name, slug, description, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :description, :is_active, NOW(), NOW())
             RETURNING id, name, slug, description, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':description', $dto->description);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        $presented = $this->present((array) $stmt->fetch());
        $this->audit->recordAdd('role', $presented['id'], $actor->userId, $actor->clinicId, $presented);

        return $presented;
    }

    public function patch(string $roleId, PatchRoleDTO $dto, AuditActor $actor): ?array
    {
        $before = $this->get($roleId);
        if ($before === null) {
            return null;
        }

        $name = $dto->name ?? (string) $before['name'];
        $slug = $dto->name !== null ? Slug::from($name) : (string) $before['slug'];

        $description = $before['description'];
        if ($dto->descriptionTouched) {
            $description = $dto->description;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE roles
             SET name = :name, slug = :slug, description = :description, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, name, slug, description, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $roleId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':is_active', $dto->isActive ?? (bool) $before['is_active'], PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $after = $this->present($row);
        $this->audit->recordEdit('role', $roleId, $actor->userId, $actor->clinicId, $before, $after);

        return $after;
    }

    public function softDelete(string $roleId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE roles SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $roleId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('role', $roleId, $actor->userId, $actor->clinicId);
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
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
