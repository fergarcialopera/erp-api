<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\DTOs\CreateProductDTO;
use App\Modules\Products\DTOs\PatchProductDTO;
use PDO;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

final class ProductService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $clinicId, ?bool $active): array
    {
        $sql = 'SELECT public_id AS id, clinic_id, sku, name, is_active, created_at, updated_at
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

    public function get(string $clinicId, string $publicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT public_id AS id, clinic_id, sku, name, is_active, created_at, updated_at
             FROM products
             WHERE clinic_id = :clinic_id AND public_id = :public_id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'public_id' => $publicId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(string $clinicId, CreateProductDTO $dto): array
    {
        $id = Uuid::v4()->toRfc4122();
        $publicId = (string) new Ulid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (id, public_id, clinic_id, name, is_active, updated_at)
             VALUES (:id, :public_id, :clinic_id, :name, :is_active, NOW())
             RETURNING public_id AS id, clinic_id, name, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'public_id' => $publicId,
            'clinic_id' => $clinicId,
            'name' => $dto->name,
            'is_active' => $dto->isActive,
        ]);
        return (array) $stmt->fetch();
    }

    public function patch(string $clinicId, string $publicId, PatchProductDTO $dto): ?array
    {
        $current = $this->get($clinicId, $publicId);
        if ($current === null) {
            return null;
        }

        $name = $dto->name ?? (string) $current['name'];
        $isActive = $dto->isActive ?? (bool) $current['is_active'];

        $stmt = $this->pdo->prepare(
            'UPDATE products
             SET name = :name, is_active = :is_active, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND public_id = :public_id
             RETURNING public_id AS id, clinic_id, name, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'public_id' => $publicId,
            'name' => $name,
            'is_active' => $isActive,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function softDelete(string $clinicId, string $publicId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET is_active = FALSE, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND public_id = :public_id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'public_id' => $publicId]);
        return $stmt->rowCount() > 0;
    }
}

