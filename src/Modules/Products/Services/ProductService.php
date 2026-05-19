<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\DTOs\CreateProductDTO;
use App\Modules\Products\DTOs\PatchProductDTO;
use PDO;
use Symfony\Component\Uid\Uuid;

final class ProductService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $clinicId, ?bool $active): array
    {
        $sql = 'SELECT id, clinic_id, sku, name, is_active, created_at, updated_at
                FROM products
                WHERE clinic_id = :clinic_id';
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

    public function get(string $clinicId, string $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, sku, name, is_active, created_at, updated_at
             FROM products
             WHERE clinic_id = :clinic_id AND id::text = :id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $productId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(string $clinicId, CreateProductDTO $dto): array
    {
        $id = Uuid::v4()->toRfc4122();
        $sku = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (id, clinic_id, sku, name, is_active, updated_at)
             VALUES (:id, :clinic_id, :sku, :name, :is_active, NOW())
             RETURNING id, clinic_id, sku, name, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':clinic_id', $clinicId);
        $stmt->bindValue(':sku', 'SKU-' . $sku);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();
        return (array) $stmt->fetch();
    }

    public function patch(string $clinicId, string $productId, PatchProductDTO $dto): ?array
    {
        $current = $this->get($clinicId, $productId);
        if ($current === null) {
            return null;
        }

        $name = $dto->name ?? (string) $current['name'];
        $isActive = $dto->isActive ?? (bool) $current['is_active'];

        $stmt = $this->pdo->prepare(
            'UPDATE products
             SET name = :name, is_active = :is_active, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id
             RETURNING id, clinic_id, sku, name, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':clinic_id', $clinicId);
        $stmt->bindValue(':id', $productId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function softDelete(string $clinicId, string $productId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET is_active = FALSE, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $productId]);
        return $stmt->rowCount() > 0;
    }
}

