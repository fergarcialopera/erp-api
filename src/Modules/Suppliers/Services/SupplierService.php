<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Services;

use App\Application\Support\Slug;
use App\Modules\Suppliers\DTOs\CreateSupplierDTO;
use App\Modules\Suppliers\DTOs\PatchSupplierDTO;
use PDO;
use Symfony\Component\Uid\Uuid;

final class SupplierService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?bool $active): array
    {
        $sql = 'SELECT id, name, slug, legal_name, tax_id, email, phone, is_active, created_at, updated_at
                FROM suppliers WHERE 1=1';
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

    public function get(string $supplierId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, legal_name, tax_id, email, phone, is_active, created_at, updated_at
             FROM suppliers WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $supplierId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function create(CreateSupplierDTO $dto): array
    {
        $id = Uuid::v4()->toRfc4122();
        $slug = Slug::from($dto->name);
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (id, name, slug, legal_name, tax_id, email, phone, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :legal_name, :tax_id, :email, :phone, :is_active, NOW(), NOW())
             RETURNING id, name, slug, legal_name, tax_id, email, phone, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':name', $dto->name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':legal_name', $dto->legalName);
        $stmt->bindValue(':tax_id', $dto->taxId);
        $stmt->bindValue(':email', $dto->email);
        $stmt->bindValue(':phone', $dto->phone);
        $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
        $stmt->execute();

        return $this->present((array) $stmt->fetch());
    }

    public function patch(string $supplierId, PatchSupplierDTO $dto): ?array
    {
        $currentStmt = $this->pdo->prepare(
            'SELECT name, slug, legal_name, tax_id, email, phone, is_active
             FROM suppliers WHERE id::text = :id LIMIT 1'
        );
        $currentStmt->execute(['id' => $supplierId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            return null;
        }

        $name = $dto->name ?? (string) $current['name'];
        $slug = $dto->name !== null ? Slug::from($name) : (string) $current['slug'];

        $legalName = $current['legal_name'];
        if ($dto->legalNameTouched) {
            $legalName = $dto->legalName;
        }
        $taxId = $current['tax_id'];
        if ($dto->taxIdTouched) {
            $taxId = $dto->taxId;
        }
        $email = $current['email'];
        if ($dto->emailTouched) {
            $email = $dto->email;
        }
        $phone = $current['phone'];
        if ($dto->phoneTouched) {
            $phone = $dto->phone;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE suppliers
             SET name = :name, slug = :slug, legal_name = :legal_name, tax_id = :tax_id,
                 email = :email, phone = :phone, is_active = :is_active, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, name, slug, legal_name, tax_id, email, phone, is_active, created_at, updated_at'
        );
        $stmt->bindValue(':id', $supplierId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':legal_name', $legalName);
        $stmt->bindValue(':tax_id', $taxId);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':phone', $phone);
        $stmt->bindValue(':is_active', $dto->isActive ?? (bool) $current['is_active'], PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $this->present($row) : null;
    }

    public function softDelete(string $supplierId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE suppliers SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $supplierId]);

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
            'legal_name' => $row['legal_name'] !== null ? (string) $row['legal_name'] : null,
            'tax_id' => $row['tax_id'] !== null ? (string) $row['tax_id'] : null,
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
