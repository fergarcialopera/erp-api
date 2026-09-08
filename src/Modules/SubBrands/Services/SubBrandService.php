<?php

declare(strict_types=1);

namespace App\Modules\SubBrands\Services;

use App\Application\Audit\AuditActor;
use App\Application\Support\Slug;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\SubBrands\DTOs\CreateSubBrandDTO;
use App\Modules\SubBrands\DTOs\PatchSubBrandDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class SubBrandService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditActivityService $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?bool $active, ?string $brandId = null): array
    {
        $sql = 'SELECT id, brand_id, name, slug, is_active, created_at, updated_at
                FROM sub_brands WHERE 1=1';
        $params = [];
        if ($brandId !== null) {
            $sql .= ' AND brand_id::text = :brand_id';
            $params['brand_id'] = $brandId;
        }
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

    public function get(string $subBrandId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, brand_id, name, slug, is_active, created_at, updated_at
             FROM sub_brands WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $subBrandId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateSubBrandDTO $dto, AuditActor $actor): array
    {
        if (!$this->brandExists($dto->brandId)) {
            throw new RuntimeException('Brand not found');
        }

        $id = Uuid::v4()->toRfc4122();
        $slug = Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO sub_brands (id, brand_id, name, slug, is_active, created_at, updated_at)
             VALUES (:id, :brand_id, :name, :slug, :is_active, NOW(), NOW())
             RETURNING id, brand_id, name, slug, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':brand_id', $dto->brandId);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        $presented = $this->present((array) $stmt->fetch());
        $this->audit->recordAdd('sub-brand', $presented['id'], $actor->userId, $actor->clinicId, $presented);

        return $presented;
    }

    public function patch(string $subBrandId, PatchSubBrandDTO $dto, AuditActor $actor): ?array
    {
        $before = $this->get($subBrandId);
        if ($before === null) {
            return null;
        }

        $brandId = (string) $before['brand_id'];
        if ($dto->brandIdTouched) {
            $brandId = (string) $dto->brandId;
            if (!$this->brandExists($brandId)) {
                throw new RuntimeException('Brand not found');
            }
        }

        $name = $dto->name ?? (string) $before['name'];
        $slug = $dto->name !== null ? Slug::from($name) : (string) $before['slug'];

        $stmt = $this->pdo->prepare(
            'UPDATE sub_brands
             SET brand_id = :brand_id, name = :name, slug = :slug,
                 is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, brand_id, name, slug, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $subBrandId);
        $stmt->bindValue(':brand_id', $brandId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_active', $dto->isActive ?? (bool) $before['is_active'], PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $after = $this->present($row);
        $this->audit->recordEdit('sub-brand', $subBrandId, $actor->userId, $actor->clinicId, $before, $after);

        return $after;
    }

    public function softDelete(string $subBrandId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sub_brands SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $subBrandId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('sub-brand', $subBrandId, $actor->userId, $actor->clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    private function brandExists(string $brandId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM brands WHERE id::text = :id LIMIT 1');
        $stmt->execute(['id' => $brandId]);

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
            'brand_id' => (string) $row['brand_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
