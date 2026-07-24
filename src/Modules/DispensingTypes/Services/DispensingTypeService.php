<?php

declare(strict_types=1);

namespace App\Modules\DispensingTypes\Services;

use App\Application\Audit\AuditActor;
use App\Application\Support\Slug;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\DispensingTypes\DTOs\CreateDispensingTypeDTO;
use App\Modules\DispensingTypes\DTOs\PatchDispensingTypeDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class DispensingTypeService
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
        $sql = 'SELECT id, name, slug, description, is_active, created_at, updated_at
                FROM dispensing_types WHERE 1=1';
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

    public function get(string $dispensingTypeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, description, is_active, created_at, updated_at
             FROM dispensing_types WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $dispensingTypeId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateDispensingTypeDTO $dto, AuditActor $actor): array
    {
        $id = Uuid::v4()->toRfc4122();
        $slug = Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO dispensing_types (id, name, slug, description, is_active, created_at, updated_at)
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
        $this->audit->recordAdd('dispensing-type', $presented['id'], $actor->userId, $actor->clinicId, $presented);

        return $presented;
    }

    public function patch(string $dispensingTypeId, PatchDispensingTypeDTO $dto, AuditActor $actor): ?array
    {
        $before = $this->get($dispensingTypeId);
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
            'UPDATE dispensing_types
             SET name = :name, slug = :slug, description = :description, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, name, slug, description, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $dispensingTypeId);
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
        $this->audit->recordEdit('dispensing-type', $dispensingTypeId, $actor->userId, $actor->clinicId, $before, $after);

        return $after;
    }

    public function softDelete(string $dispensingTypeId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispensing_types SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $dispensingTypeId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('dispensing-type', $dispensingTypeId, $actor->userId, $actor->clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRoles(string $dispensingTypeId): array
    {
        if ($this->get($dispensingTypeId) === null) {
            throw new RuntimeException('Dispensing type not found');
        }

        $stmt = $this->pdo->prepare(
            'SELECT dtr.id, dtr.dispensing_type_id, dtr.role_id, dtr.created_at, dtr.updated_at,
                    r.name AS role_name, r.slug AS role_slug
             FROM dispensing_type_roles dtr
             INNER JOIN roles r ON r.id = dtr.role_id
             WHERE dtr.dispensing_type_id::text = :dispensing_type_id
             ORDER BY r.name ASC'
        );
        $stmt->execute(['dispensing_type_id' => $dispensingTypeId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentRoleLink($row), $rows);
    }

    public function attachRole(string $dispensingTypeId, string $roleId): ?array
    {
        if ($this->get($dispensingTypeId) === null) {
            return null;
        }
        if (!$this->roleExists($roleId)) {
            return null;
        }

        $id = Uuid::v4()->toRfc4122();
        $stmt = $this->pdo->prepare(
            'INSERT INTO dispensing_type_roles (id, dispensing_type_id, role_id, created_at, updated_at)
             VALUES (:id, :dispensing_type_id, :role_id, NOW(), NOW())
             ON CONFLICT (dispensing_type_id, role_id) DO UPDATE SET updated_at = NOW()
             RETURNING id, dispensing_type_id, role_id, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':dispensing_type_id', $dispensingTypeId);
        $stmt->bindValue(':role_id', $roleId);
        $stmt->execute();
        $link = $stmt->fetch();
        if (!is_array($link)) {
            return null;
        }

        $roleStmt = $this->pdo->prepare('SELECT name, slug FROM roles WHERE id::text = :id LIMIT 1');
        $roleStmt->execute(['id' => $roleId]);
        $role = $roleStmt->fetch();
        if (is_array($role)) {
            $link['role_name'] = (string) $role['name'];
            $link['role_slug'] = (string) $role['slug'];
        } else {
            $link['role_name'] = '';
            $link['role_slug'] = '';
        }

        return $this->presentRoleLink($link);
    }

    public function detachRole(string $dispensingTypeId, string $roleId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM dispensing_type_roles
             WHERE dispensing_type_id::text = :dispensing_type_id AND role_id::text = :role_id'
        );
        $stmt->execute(['dispensing_type_id' => $dispensingTypeId, 'role_id' => $roleId]);

        return $stmt->rowCount() > 0;
    }

    private function roleExists(string $roleId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM roles WHERE id::text = :id LIMIT 1');
        $stmt->execute(['id' => $roleId]);

        return (bool) $stmt->fetch();
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRoleLink(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'dispensing_type_id' => (string) $row['dispensing_type_id'],
            'role_id' => (string) $row['role_id'],
            'role_name' => (string) ($row['role_name'] ?? ''),
            'role_slug' => (string) ($row['role_slug'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
