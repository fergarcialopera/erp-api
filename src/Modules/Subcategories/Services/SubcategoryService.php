<?php

declare(strict_types=1);

namespace App\Modules\Subcategories\Services;

use App\Application\Support\Slug;
use App\Modules\Subcategories\DTOs\CreateSubcategoryDTO;
use App\Modules\Subcategories\DTOs\PatchSubcategoryDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class SubcategoryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?bool $active, ?string $categoryId = null): array
    {
        $sql = 'SELECT id, category_id, name, slug, description, is_active, created_at, updated_at
                FROM subcategories WHERE 1=1';
        $params = [];
        if ($categoryId !== null) {
            $sql .= ' AND category_id::text = :category_id';
            $params['category_id'] = $categoryId;
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

    public function get(string $subcategoryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, category_id, name, slug, description, is_active, created_at, updated_at
             FROM subcategories WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $subcategoryId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateSubcategoryDTO $dto): array
    {
        if (!$this->categoryExists($dto->categoryId)) {
            throw new RuntimeException('Category not found');
        }

        $id = Uuid::v4()->toRfc4122();
        $slug = Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO subcategories (id, category_id, name, slug, description, is_active, created_at, updated_at)
             VALUES (:id, :category_id, :name, :slug, :description, :is_active, NOW(), NOW())
             RETURNING id, category_id, name, slug, description, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':category_id', $dto->categoryId);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':description', $dto->description);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        return $this->present((array) $stmt->fetch());
    }

    public function patch(string $subcategoryId, PatchSubcategoryDTO $dto): ?array
    {
        $currentStmt = $this->pdo->prepare(
            'SELECT category_id, name, slug, description, is_active FROM subcategories WHERE id::text = :id LIMIT 1'
        );
        $currentStmt->execute(['id' => $subcategoryId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            return null;
        }

        $categoryId = (string) $current['category_id'];
        if ($dto->categoryIdTouched) {
            $categoryId = (string) $dto->categoryId;
            if (!$this->categoryExists($categoryId)) {
                throw new RuntimeException('Category not found');
            }
        }

        $name = $dto->name ?? (string) $current['name'];
        $slug = $dto->name !== null ? Slug::from($name) : (string) $current['slug'];

        $description = $current['description'];
        if ($dto->descriptionTouched) {
            $description = $dto->description;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE subcategories
             SET category_id = :category_id, name = :name, slug = :slug, description = :description,
                 is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, category_id, name, slug, description, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $subcategoryId);
        $stmt->bindValue(':category_id', $categoryId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':is_active', $dto->isActive ?? (bool) $current['is_active'], PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function softDelete(string $subcategoryId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE subcategories SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $subcategoryId]);

        return $stmt->rowCount() > 0;
    }

    private function categoryExists(string $categoryId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM categories WHERE id::text = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);

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
            'category_id' => (string) $row['category_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
