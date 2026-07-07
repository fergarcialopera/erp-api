<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Application\Audit\AuditActor;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Products\DTOs\CreateProductDTO;
use App\Modules\Products\DTOs\PatchProductDTO;
use PDO;
use Symfony\Component\Uid\Uuid;

final class ProductService
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
        $sql = 'SELECT p.id, p.sku, p.name, p.is_active, p.created_at, p.updated_at, cp.visible
                FROM products p
                INNER JOIN clinic_products cp ON cp.product_id = p.id AND cp.clinic_id = :clinic_id
                WHERE 1=1';
        $params = ['clinic_id' => $clinicId];

        if (!$adminView) {
            $sql .= ' AND cp.visible = TRUE AND p.is_active = TRUE';
        } elseif ($active !== null) {
            $sql .= ' AND p.is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }

        $sql .= ' ORDER BY p.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentClinicProduct($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGlobal(?bool $active): array
    {
        $sql = 'SELECT id, sku, name, is_active, created_at, updated_at FROM products WHERE 1=1';
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

    public function getForClinic(string $clinicId, string $productId, bool $adminView): ?array
    {
        $sql = 'SELECT p.id, p.sku, p.name, p.is_active, p.created_at, p.updated_at, cp.visible
                FROM products p
                INNER JOIN clinic_products cp ON cp.product_id = p.id AND cp.clinic_id = :clinic_id
                WHERE p.id::text = :id';
        if (!$adminView) {
            $sql .= ' AND cp.visible = TRUE AND p.is_active = TRUE';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentClinicProduct($row) : null;
    }

    public function getGlobal(string $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, sku, name, is_active, created_at, updated_at
             FROM products WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function create(CreateProductDTO $dto, AuditActor $actor): array
    {
        $id = Uuid::v4()->toRfc4122();
        $sku = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (id, sku, name, is_active, updated_at)
                 VALUES (:id, :sku, :name, :is_active, NOW())
                 RETURNING id, sku, name, is_active, created_at, updated_at'
            );
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':sku', 'SKU-' . $sku);
            $stmt->bindValue(':name', $dto->name);
            $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
            $stmt->execute();
            $product = (array) $stmt->fetch();

            $clinicStmt = $this->pdo->query('SELECT id FROM clinics');
            $clinics = $clinicStmt->fetchAll() ?: [];
            $link = $this->pdo->prepare(
                'INSERT INTO clinic_products (clinic_id, product_id, visible)
                 VALUES (:clinic_id, :product_id, FALSE)
                 ON CONFLICT DO NOTHING'
            );
            foreach ($clinics as $clinic) {
                if (!is_array($clinic)) {
                    continue;
                }
                $link->execute([
                    'clinic_id' => (string) $clinic['id'],
                    'product_id' => $id,
                ]);
            }

            $this->pdo->commit();

            $presented = $this->presentGlobalProduct($product);
            $this->audit->recordAdd('product', (string) $product['id'], $actor->userId, $actor->clinicId, $presented);

            return $product;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function patch(string $productId, PatchProductDTO $dto, AuditActor $actor): ?array
    {
        $current = $this->getGlobal($productId);
        if ($current === null) {
            return null;
        }

        $before = $this->presentGlobalProduct($current);

        $name = $dto->name ?? (string) $current['name'];
        $isActive = $dto->isActive ?? (bool) $current['is_active'];

        $stmt = $this->pdo->prepare(
            'UPDATE products
             SET name = :name, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, sku, name, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $productId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $after = $this->presentGlobalProduct($row);
        $this->audit->recordEdit('product', $productId, $actor->userId, $actor->clinicId, $before, $after);

        return $row;
    }

    public function softDelete(string $productId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $productId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('product', $productId, $actor->userId, $actor->clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    public function setClinicVisibility(string $clinicId, string $productId, bool $visible, AuditActor $actor): ?array
    {
        $product = $this->getGlobal($productId);
        if ($product === null || !(bool) $product['is_active']) {
            return null;
        }

        $before = $this->getForClinic($clinicId, $productId, true);

        $stmt = $this->pdo->prepare(
            'INSERT INTO clinic_products (clinic_id, product_id, visible)
             VALUES (:clinic_id, :product_id, :visible)
             ON CONFLICT (clinic_id, product_id)
             DO UPDATE SET visible = EXCLUDED.visible
             RETURNING clinic_id, product_id, visible'
        );
        $stmt->bindValue(':clinic_id', $clinicId);
        $stmt->bindValue(':product_id', $productId);
        $stmt->bindValue(':visible', $visible, PDO::PARAM_BOOL);
        $stmt->execute();
        if (!$stmt->fetch()) {
            return null;
        }

        if (!$stmt->fetch()) {
            return null;
        }

        $after = $this->getForClinic($clinicId, $productId, true);
        if ($after !== null) {
            $this->audit->recordEdit(
                'clinic-product',
                $productId,
                $actor->userId,
                $clinicId,
                $before ?? ['product_id' => $productId, 'clinic_id' => $clinicId, 'visible' => !$visible],
                $after,
            );
        }

        return $after;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentGlobalProduct(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentClinicProduct(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'is_active' => (bool) $row['is_active'],
            'visible' => (bool) ($row['visible'] ?? true),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
