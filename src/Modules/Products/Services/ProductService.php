<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Application\Audit\AuditActor;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Products\DTOs\CreateProductDTO;
use App\Modules\Products\DTOs\PatchProductDTO;
use App\Modules\Products\DTOs\PatchProductSupplierDTO;
use App\Modules\Products\DTOs\UpsertProductSupplierDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class ProductService
{
    private const SELECT_COLUMNS = 'p.id, p.sku, p.name, p.barcode, p.internal_reference,
            p.category_id, p.subcategory_id, p.brand_id, p.dispensing_type_id,
            p.is_active, p.unit_of_measure, p.created_at, p.updated_at,
            c.id AS category_rel_id, c.name AS category_name,
            sc.id AS subcategory_rel_id, sc.name AS subcategory_name,
            b.id AS brand_rel_id, b.name AS brand_name,
            dt.id AS dispensing_type_rel_id, dt.name AS dispensing_type_name';

    private const JOINS = 'LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN subcategories sc ON sc.id = p.subcategory_id
         LEFT JOIN brands b ON b.id = p.brand_id
         LEFT JOIN dispensing_types dt ON dt.id = p.dispensing_type_id';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditActivityService $audit,
    ) {
    }

    /**
     * @param array{category_id:?string,subcategory_id:?string,brand_id:?string,dispensing_type_id:?string,supplier_id:?string,search:?string}|null $filters
     * @return list<array<string, mixed>>
     */
    public function listForClinic(
        string $clinicId,
        ?bool $active,
        bool $adminView,
        ?array $filters = null
    ): array {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . ', cp.visible
                FROM products p
                INNER JOIN clinic_products cp ON cp.product_id = p.id AND cp.clinic_id = :clinic_id
                ' . self::JOINS . '
                WHERE 1=1';
        $params = ['clinic_id' => $clinicId];

        if (!$adminView) {
            $sql .= ' AND cp.visible = TRUE AND p.is_active = TRUE';
        } elseif ($active !== null) {
            $sql .= ' AND p.is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }

        [$sql, $params] = $this->applyListFilters($sql, $params, $filters);

        $sql .= ' ORDER BY p.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentProduct($row, true, false), $rows);
    }

    /**
     * @param array{category_id:?string,subcategory_id:?string,brand_id:?string,dispensing_type_id:?string,supplier_id:?string,search:?string}|null $filters
     * @return list<array<string, mixed>>
     */
    public function listGlobal(?bool $active, ?array $filters = null): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . '
                FROM products p
                ' . self::JOINS . '
                WHERE 1=1';
        $params = [];
        if ($active !== null) {
            $sql .= ' AND p.is_active = :is_active';
            $params['is_active'] = $active ? 'true' : 'false';
        }

        [$sql, $params] = $this->applyListFilters($sql, $params, $filters);

        $sql .= ' ORDER BY p.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentProduct($row, false, false), $rows);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{category_id:?string,subcategory_id:?string,brand_id:?string,dispensing_type_id:?string,supplier_id:?string,search:?string}|null $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function applyListFilters(string $sql, array $params, ?array $filters): array
    {
        if ($filters === null) {
            return [$sql, $params];
        }

        if (!empty($filters['category_id'])) {
            $sql .= ' AND p.category_id::text = :filter_category_id';
            $params['filter_category_id'] = $filters['category_id'];
        }
        if (!empty($filters['subcategory_id'])) {
            $sql .= ' AND p.subcategory_id::text = :filter_subcategory_id';
            $params['filter_subcategory_id'] = $filters['subcategory_id'];
        }
        if (!empty($filters['brand_id'])) {
            $sql .= ' AND p.brand_id::text = :filter_brand_id';
            $params['filter_brand_id'] = $filters['brand_id'];
        }
        if (!empty($filters['dispensing_type_id'])) {
            $sql .= ' AND p.dispensing_type_id::text = :filter_dispensing_type_id';
            $params['filter_dispensing_type_id'] = $filters['dispensing_type_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM product_suppliers ps
                WHERE ps.product_id = p.id AND ps.supplier_id::text = :filter_supplier_id
            )';
            $params['filter_supplier_id'] = $filters['supplier_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (
                p.name ILIKE :filter_search
                OR COALESCE(p.barcode, \'\') ILIKE :filter_search
                OR COALESCE(p.internal_reference, \'\') ILIKE :filter_search
                OR p.sku ILIKE :filter_search
            )';
            $params['filter_search'] = '%' . $filters['search'] . '%';
        }

        return [$sql, $params];
    }

    public function getForClinic(string $clinicId, string $productId, bool $adminView): ?array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . ', cp.visible
                FROM products p
                INNER JOIN clinic_products cp ON cp.product_id = p.id AND cp.clinic_id = :clinic_id
                ' . self::JOINS . '
                WHERE p.id::text = :id';
        if (!$adminView) {
            $sql .= ' AND cp.visible = TRUE AND p.is_active = TRUE';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentProduct($row, true, true) : null;
    }

    public function getGlobal(string $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM products p
             ' . self::JOINS . '
             WHERE p.id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentProduct($row, false, true) : null;
    }

    public function create(CreateProductDTO $dto, AuditActor $actor): array
    {
        $this->assertCatalogRelations(
            $dto->categoryId,
            $dto->subcategoryId,
            $dto->brandId,
            $dto->dispensingTypeId,
        );

        $id = Uuid::v4()->toRfc4122();
        $sku = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (
                    id, sku, name, barcode, internal_reference,
                    category_id, subcategory_id, brand_id, dispensing_type_id,
                    is_active, unit_of_measure, updated_at
                 ) VALUES (
                    :id, :sku, :name, :barcode, :internal_reference,
                    :category_id, :subcategory_id, :brand_id, :dispensing_type_id,
                    :is_active, :unit_of_measure, NOW()
                 )'
            );
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':sku', 'SKU-' . $sku);
            $stmt->bindValue(':name', $dto->name);
            $stmt->bindValue(':barcode', $dto->barcode);
            $stmt->bindValue(':internal_reference', $dto->internalReference);
            $stmt->bindValue(':category_id', $dto->categoryId);
            $stmt->bindValue(':subcategory_id', $dto->subcategoryId);
            $stmt->bindValue(':brand_id', $dto->brandId);
            $stmt->bindValue(':dispensing_type_id', $dto->dispensingTypeId);
            $stmt->bindValue(':is_active', $dto->isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':unit_of_measure', $dto->unitOfMeasure);
            $stmt->execute();

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

            $product = $this->getGlobal($id);
            if ($product === null) {
                throw new RuntimeException('Product created but not found');
            }
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
        $current = $this->getRaw($productId);
        if ($current === null) {
            return null;
        }

        $before = $this->presentGlobalProduct($current);

        $name = $dto->name ?? (string) $current['name'];
        $isActive = $dto->isActive ?? (bool) $current['is_active'];
        $barcode = $dto->barcodeTouched ? $dto->barcode : ($current['barcode'] !== null ? (string) $current['barcode'] : null);
        $internalReference = $dto->internalReferenceTouched
            ? $dto->internalReference
            : ($current['internal_reference'] !== null ? (string) $current['internal_reference'] : null);
        $categoryId = $dto->categoryIdTouched
            ? $dto->categoryId
            : ($current['category_id'] !== null ? (string) $current['category_id'] : null);
        $subcategoryId = $dto->subcategoryIdTouched
            ? $dto->subcategoryId
            : ($current['subcategory_id'] !== null ? (string) $current['subcategory_id'] : null);
        $brandId = $dto->brandIdTouched
            ? $dto->brandId
            : ($current['brand_id'] !== null ? (string) $current['brand_id'] : null);
        $dispensingTypeId = $dto->dispensingTypeIdTouched
            ? $dto->dispensingTypeId
            : ($current['dispensing_type_id'] !== null ? (string) $current['dispensing_type_id'] : null);
        $unitOfMeasure = $dto->unitOfMeasure ?? (string) ($current['unit_of_measure'] ?? 'Unidades');

        $this->assertCatalogRelations($categoryId, $subcategoryId, $brandId, $dispensingTypeId);

        $stmt = $this->pdo->prepare(
            'UPDATE products SET
                name = :name,
                barcode = :barcode,
                internal_reference = :internal_reference,
                category_id = :category_id,
                subcategory_id = :subcategory_id,
                brand_id = :brand_id,
                dispensing_type_id = :dispensing_type_id,
                is_active = :is_active,
                unit_of_measure = :unit_of_measure,
                updated_at = NOW()
             WHERE id::text = :id'
        );
        $stmt->bindValue(':id', $productId);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':barcode', $barcode);
        $stmt->bindValue(':internal_reference', $internalReference);
        $stmt->bindValue(':category_id', $categoryId);
        $stmt->bindValue(':subcategory_id', $subcategoryId);
        $stmt->bindValue(':brand_id', $brandId);
        $stmt->bindValue(':dispensing_type_id', $dispensingTypeId);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $stmt->bindValue(':unit_of_measure', $unitOfMeasure);
        $stmt->execute();

        $row = $this->getGlobal($productId);
      
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
     * @return list<array<string, mixed>>
     */
    public function listSuppliers(string $productId): array
    {
        if ($this->getRaw($productId) === null) {
            throw new RuntimeException('Product not found');
        }

        $stmt = $this->pdo->prepare(
            'SELECT ps.id, ps.product_id, ps.supplier_id, ps.supplier_reference,
                    ps.purchase_price, ps.pvp, ps.net_cost, ps.is_preferred,
                    ps.created_at, ps.updated_at, s.name AS supplier_name
             FROM product_suppliers ps
             INNER JOIN suppliers s ON s.id = ps.supplier_id
             WHERE ps.product_id::text = :product_id
             ORDER BY ps.is_preferred DESC, s.name ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentSupplierLink($row), $rows);
    }

    public function addSupplier(string $productId, UpsertProductSupplierDTO $dto): array
    {
        if ($this->getRaw($productId) === null) {
            throw new RuntimeException('Product not found');
        }
        if (!$this->exists('suppliers', $dto->supplierId)) {
            throw new RuntimeException('Supplier not found');
        }

        $this->pdo->beginTransaction();
        try {
            if ($dto->isPreferred) {
                $this->clearPreferred($productId);
            }

            $id = Uuid::v4()->toRfc4122();
            $stmt = $this->pdo->prepare(
                'INSERT INTO product_suppliers (
                    id, product_id, supplier_id, supplier_reference,
                    purchase_price, pvp, net_cost, is_preferred, created_at, updated_at
                 ) VALUES (
                    :id, :product_id, :supplier_id, :supplier_reference,
                    :purchase_price, :pvp, :net_cost, :is_preferred, NOW(), NOW()
                 )
                 RETURNING id'
            );
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':product_id', $productId);
            $stmt->bindValue(':supplier_id', $dto->supplierId);
            $stmt->bindValue(':supplier_reference', $dto->supplierReference);
            $stmt->bindValue(':purchase_price', $dto->purchasePrice);
            $stmt->bindValue(':pvp', $dto->pvp);
            $stmt->bindValue(':net_cost', $dto->netCost);
            $stmt->bindValue(':is_preferred', $dto->isPreferred, PDO::PARAM_BOOL);
            $stmt->execute();

            $this->pdo->commit();

            $link = $this->getSupplierLink($productId, $id);
            if ($link === null) {
                throw new RuntimeException('Product supplier created but not found');
            }

            return $link;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateSupplier(string $productId, string $productSupplierId, PatchProductSupplierDTO $dto): ?array
    {
        $current = $this->getSupplierRaw($productId, $productSupplierId);
        if ($current === null) {
            return null;
        }

        $supplierId = $dto->supplierIdTouched ? (string) $dto->supplierId : (string) $current['supplier_id'];
        if ($dto->supplierIdTouched && !$this->exists('suppliers', $supplierId)) {
            throw new RuntimeException('Supplier not found');
        }

        $supplierReference = $dto->supplierReferenceTouched
            ? $dto->supplierReference
            : ($current['supplier_reference'] !== null ? (string) $current['supplier_reference'] : null);
        $purchasePrice = $dto->purchasePriceTouched
            ? $dto->purchasePrice
            : ($current['purchase_price'] !== null ? (float) $current['purchase_price'] : null);
        $pvp = $dto->pvpTouched
            ? $dto->pvp
            : ($current['pvp'] !== null ? (float) $current['pvp'] : null);
        $netCost = $dto->netCostTouched
            ? $dto->netCost
            : ($current['net_cost'] !== null ? (float) $current['net_cost'] : null);
        $isPreferred = $dto->isPreferred ?? (bool) $current['is_preferred'];

        $this->pdo->beginTransaction();
        try {
            if ($isPreferred) {
                $this->clearPreferred($productId, $productSupplierId);
            }

            $stmt = $this->pdo->prepare(
                'UPDATE product_suppliers SET
                    supplier_id = :supplier_id,
                    supplier_reference = :supplier_reference,
                    purchase_price = :purchase_price,
                    pvp = :pvp,
                    net_cost = :net_cost,
                    is_preferred = :is_preferred,
                    updated_at = NOW()
                 WHERE id::text = :id AND product_id::text = :product_id'
            );
            $stmt->bindValue(':id', $productSupplierId);
            $stmt->bindValue(':product_id', $productId);
            $stmt->bindValue(':supplier_id', $supplierId);
            $stmt->bindValue(':supplier_reference', $supplierReference);
            $stmt->bindValue(':purchase_price', $purchasePrice);
            $stmt->bindValue(':pvp', $pvp);
            $stmt->bindValue(':net_cost', $netCost);
            $stmt->bindValue(':is_preferred', $isPreferred, PDO::PARAM_BOOL);
            $stmt->execute();

            $this->pdo->commit();

            return $this->getSupplierLink($productId, $productSupplierId);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteSupplier(string $productId, string $productSupplierId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM product_suppliers WHERE id::text = :id AND product_id::text = :product_id'
        );
        $stmt->execute(['id' => $productSupplierId, 'product_id' => $productId]);

        return $stmt->rowCount() > 0;
    }

    public function setPreferredSupplier(string $productId, string $productSupplierId): ?array
    {
        if ($this->getSupplierRaw($productId, $productSupplierId) === null) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->clearPreferred($productId);
            $stmt = $this->pdo->prepare(
                'UPDATE product_suppliers SET is_preferred = TRUE, updated_at = NOW()
                 WHERE id::text = :id AND product_id::text = :product_id'
            );
            $stmt->execute(['id' => $productSupplierId, 'product_id' => $productId]);
            $this->pdo->commit();

            return $this->getSupplierLink($productId, $productSupplierId);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRaw(string $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, sku, name, barcode, internal_reference, category_id, subcategory_id,
                    brand_id, dispensing_type_id, is_active, unit_of_measure
             FROM products WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function assertCatalogRelations(
        ?string $categoryId,
        ?string $subcategoryId,
        ?string $brandId,
        ?string $dispensingTypeId,
    ): void {
        if ($categoryId !== null && !$this->exists('categories', $categoryId)) {
            throw new RuntimeException('Category not found');
        }
        if ($brandId !== null && !$this->exists('brands', $brandId)) {
            throw new RuntimeException('Brand not found');
        }
        if ($dispensingTypeId !== null && !$this->exists('dispensing_types', $dispensingTypeId)) {
            throw new RuntimeException('Dispensing type not found');
        }
        if ($subcategoryId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT category_id::text AS category_id FROM subcategories WHERE id::text = :id LIMIT 1'
            );
            $stmt->execute(['id' => $subcategoryId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('Subcategory not found');
            }
            if ($categoryId !== null && (string) $row['category_id'] !== $categoryId) {
                throw new RuntimeException('Subcategory does not belong to the given category');
            }
        }
    }

    private function exists(string $table, string $id): bool
    {
        $allowed = ['categories', 'brands', 'dispensing_types', 'suppliers'];
        if (!in_array($table, $allowed, true)) {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id::text = :id LIMIT 1");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetch();
    }

    private function clearPreferred(string $productId, ?string $exceptId = null): void
    {
        $sql = 'UPDATE product_suppliers SET is_preferred = FALSE, updated_at = NOW()
                WHERE product_id::text = :product_id AND is_preferred = TRUE';
        $params = ['product_id' => $productId];
        if ($exceptId !== null) {
            $sql .= ' AND id::text <> :except_id';
            $params['except_id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getSupplierRaw(string $productId, string $productSupplierId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, product_id, supplier_id, supplier_reference, purchase_price, pvp, net_cost, is_preferred
             FROM product_suppliers
             WHERE id::text = :id AND product_id::text = :product_id LIMIT 1'
        );
        $stmt->execute(['id' => $productSupplierId, 'product_id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getSupplierLink(string $productId, string $productSupplierId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ps.id, ps.product_id, ps.supplier_id, ps.supplier_reference,
                    ps.purchase_price, ps.pvp, ps.net_cost, ps.is_preferred,
                    ps.created_at, ps.updated_at, s.name AS supplier_name
             FROM product_suppliers ps
             INNER JOIN suppliers s ON s.id = ps.supplier_id
             WHERE ps.id::text = :id AND ps.product_id::text = :product_id LIMIT 1'
        );
        $stmt->execute(['id' => $productSupplierId, 'product_id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentSupplierLink($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadSuppliersForProduct(string $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ps.id, ps.product_id, ps.supplier_id, ps.supplier_reference,
                    ps.purchase_price, ps.pvp, ps.net_cost, ps.is_preferred,
                    ps.created_at, ps.updated_at, s.name AS supplier_name
             FROM product_suppliers ps
             INNER JOIN suppliers s ON s.id = ps.supplier_id
             WHERE ps.product_id::text = :product_id
             ORDER BY ps.is_preferred DESC, s.name ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentSupplierLink($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentProduct(array $row, bool $includeVisible, bool $withSuppliers): array
    {
        $productId = (string) $row['id'];
        $data = [
            'id' => $productId,
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'barcode' => $row['barcode'] !== null ? (string) $row['barcode'] : null,
            'internal_reference' => $row['internal_reference'] !== null ? (string) $row['internal_reference'] : null,
            'category_id' => $row['category_id'] !== null ? (string) $row['category_id'] : null,
            'subcategory_id' => $row['subcategory_id'] !== null ? (string) $row['subcategory_id'] : null,
            'brand_id' => $row['brand_id'] !== null ? (string) $row['brand_id'] : null,
            'dispensing_type_id' => $row['dispensing_type_id'] !== null ? (string) $row['dispensing_type_id'] : null,
            'is_active' => (bool) $row['is_active'],
            'unit_of_measure' => (string) ($row['unit_of_measure'] ?? 'Unidades'),
            'category' => $row['category_rel_id'] !== null
                ? ['id' => (string) $row['category_rel_id'], 'name' => (string) $row['category_name']]
                : null,
            'subcategory' => $row['subcategory_rel_id'] !== null
                ? ['id' => (string) $row['subcategory_rel_id'], 'name' => (string) $row['subcategory_name']]
                : null,
            'brand' => $row['brand_rel_id'] !== null
                ? ['id' => (string) $row['brand_rel_id'], 'name' => (string) $row['brand_name']]
                : null,
            'dispensing_type' => $row['dispensing_type_rel_id'] !== null
                ? ['id' => (string) $row['dispensing_type_rel_id'], 'name' => (string) $row['dispensing_type_name']]
                : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];

        if ($withSuppliers) {
            $data['suppliers'] = $this->loadSuppliersForProduct($productId);
        }

        if ($includeVisible) {
            $data['visible'] = (bool) ($row['visible'] ?? true);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentSupplierLink(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'product_id' => (string) $row['product_id'],
            'supplier_id' => (string) $row['supplier_id'],
            'name' => (string) ($row['supplier_name'] ?? ''),
            'supplier_reference' => $row['supplier_reference'] !== null ? (string) $row['supplier_reference'] : null,
            'purchase_price' => $row['purchase_price'] !== null ? (float) $row['purchase_price'] : null,
            'pvp' => $row['pvp'] !== null ? (float) $row['pvp'] : null,
            'net_cost' => $row['net_cost'] !== null ? (float) $row['net_cost'] : null,
            'is_preferred' => (bool) $row['is_preferred'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
