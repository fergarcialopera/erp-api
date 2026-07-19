<?php

declare(strict_types=1);

namespace App\Modules\Categories\Services;

use App\Application\Support\Slug;
use App\Modules\Categories\DTOs\CreateCategoryDTO;
use App\Modules\Categories\DTOs\PatchCategoryDTO;
use PDO;
use Symfony\Component\Uid\Uuid;

final class CategoryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?bool $active): array
    {
        $sql = 'SELECT id, name, slug, description, is_active, created_at, updated_at FROM categories WHERE 1=1';
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

    public function get(string $categoryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, description, is_active, created_at, updated_at
             FROM categories WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $categoryId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateCategoryDTO $dto): array
    {
        $id = Uuid::v4()->toRfc4122();
        $slug = $dto->slug ?? Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (id, name, slug, description, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :description, :is_active, NOW(), NOW())
             RETURNING id, name, slug, description, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':description', $dto->description);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        return $this->present((array) $stmt->fetch());
    }

    public function patch(string $categoryId, PatchCategoryDTO $dto): ?array
    {
        $currentStmt = $this->pdo->prepare(
            'SELECT name, slug, description, is_active FROM categories WHERE id::text = :id LIMIT 1'
        );
        $currentStmt->execute(['id' => $categoryId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            return null;
        }

        $name = $dto->name ?? (string) $current['name'];
        $slug = (string) $current['slug'];
        if ($dto->slugTouched) {
            $slug = (string) $dto->slug;
        } elseif ($dto->name !== null) {
            $slug = Slug::from($name);
        }

        $description = $current['description'];
        if ($dto->descriptionTouched) {
            $description = $dto->description;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE categories
             SET name = :name, slug = :slug, description = :description, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, name, slug, description, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $categoryId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':is_active', $dto->isActive ?? (bool) $current['is_active'], PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function softDelete(string $categoryId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE categories SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $categoryId]);

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
