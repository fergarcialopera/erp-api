<?php

declare(strict_types=1);

namespace App\Modules\Brands\Services;

use App\Application\Audit\AuditActor;
use App\Application\Support\Slug;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Brands\DTOs\CreateBrandDTO;
use App\Modules\Brands\DTOs\PatchBrandDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class BrandService
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
        $sql = 'SELECT id, name, slug, is_active, created_at, updated_at FROM brands WHERE 1=1';
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

    public function get(string $brandId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, is_active, created_at, updated_at
             FROM brands WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $brandId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateBrandDTO $dto, AuditActor $actor): array
    {
        $id = Uuid::v4()->toRfc4122();
        $slug = Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO brands (id, name, slug, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :is_active, NOW(), NOW())
             RETURNING id, name, slug, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        $presented = $this->present((array) $stmt->fetch());
        $this->audit->recordAdd('brand', $presented['id'], $actor->userId, $actor->clinicId, $presented);

        return $presented;
    }

    public function patch(string $brandId, PatchBrandDTO $dto, AuditActor $actor): ?array
    {
        $before = $this->get($brandId);
        if ($before === null) {
            return null;
        }

        $name = $dto->name ?? (string) $before['name'];
        $slug = $dto->name !== null ? Slug::from($name) : (string) $before['slug'];

        $stmt = $this->pdo->prepare(
            'UPDATE brands
             SET name = :name, slug = :slug, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, name, slug, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $brandId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_active', $dto->isActive ?? (bool) $before['is_active'], PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $after = $this->present($row);
        $this->audit->recordEdit('brand', $brandId, $actor->userId, $actor->clinicId, $before, $after);

        return $after;
    }

    public function softDelete(string $brandId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE brands SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $brandId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('brand', $brandId, $actor->userId, $actor->clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSuppliers(string $brandId): array
    {
        if ($this->get($brandId) === null) {
            throw new RuntimeException('Brand not found');
        }

        $stmt = $this->pdo->prepare(
            'SELECT bs.id, bs.brand_id, bs.supplier_id, bs.is_active, bs.created_at, bs.updated_at,
                    s.name AS supplier_name
             FROM brand_supplier bs
             INNER JOIN suppliers s ON s.id = bs.supplier_id
             WHERE bs.brand_id::text = :brand_id
             ORDER BY s.name ASC'
        );
        $stmt->execute(['brand_id' => $brandId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentSupplierLink($row), $rows);
    }

    public function attachSupplier(string $brandId, string $supplierId, bool $isActive): ?array
    {
        if ($this->get($brandId) === null) {
            return null;
        }
        if (!$this->supplierExists($supplierId)) {
            return null;
        }

        $id = Uuid::v4()->toRfc4122();
        $stmt = $this->pdo->prepare(
            'INSERT INTO brand_supplier (id, brand_id, supplier_id, is_active, created_at, updated_at)
             VALUES (:id, :brand_id, :supplier_id, :is_active, NOW(), NOW())
             ON CONFLICT (brand_id, supplier_id)
             DO UPDATE SET is_active = EXCLUDED.is_active, updated_at = NOW()
             RETURNING id, brand_id, supplier_id, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':brand_id', $brandId);
        $stmt->bindValue(':supplier_id', $supplierId);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $stmt->execute();
        $link = $stmt->fetch();
        if (!is_array($link)) {
            return null;
        }

        $nameStmt = $this->pdo->prepare('SELECT name FROM suppliers WHERE id::text = :id LIMIT 1');
        $nameStmt->execute(['id' => $supplierId]);
        $supplier = $nameStmt->fetch();
        $link['supplier_name'] = is_array($supplier) ? (string) $supplier['name'] : '';

        return $this->presentSupplierLink($link);
    }

    public function detachSupplier(string $brandId, string $supplierId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM brand_supplier
             WHERE brand_id::text = :brand_id AND supplier_id::text = :supplier_id'
        );
        $stmt->execute(['brand_id' => $brandId, 'supplier_id' => $supplierId]);

        return $stmt->rowCount() > 0;
    }

    private function supplierExists(string $supplierId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM suppliers WHERE id::text = :id LIMIT 1');
        $stmt->execute(['id' => $supplierId]);

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
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentSupplierLink(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'brand_id' => (string) $row['brand_id'],
            'supplier_id' => (string) $row['supplier_id'],
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
