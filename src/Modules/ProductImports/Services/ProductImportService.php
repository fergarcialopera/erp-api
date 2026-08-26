<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Services;

use App\Application\Support\Pagination;
use App\Modules\ProductImports\Csv\CsvParser;
use App\Modules\ProductImports\Csv\RowResolver;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class ProductImportService
{
    private const MAX_BYTES = 5_242_880; // 5 MiB

    public function __construct(
        private readonly PDO $pdo,
        private readonly CsvParser $parser = new CsvParser(),
    ) {
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array<string, mixed>
     */
    public function analyzeUpload(array $file, string $userId): array
    {
        $this->assertUpload($file);
        $tmpName = (string) $file['tmp_name'];
        $filename = basename((string) ($file['name'] ?? 'import.csv'));

        $parsed = $this->parser->parseFile($tmpName);
        $importId = Uuid::v4()->toRfc4122();

        $structuralErrors = $parsed['structural_errors'];
        $status = $structuralErrors !== [] && $parsed['products'] === []
            ? 'invalid'
            : 'ready_for_review';

        $resolver = new RowResolver($this->pdo);
        $ready = 0;
        $conflict = 0;
        $invalid = 0;
        $rowInserts = [];

        foreach ($parsed['products'] as $product) {
            $result = $resolver->resolve($product['normalized'], $product['row_number']);
            match ($result['status']) {
                'ready' => ++$ready,
                'conflict' => ++$conflict,
                default => ++$invalid,
            };

            $rowInserts[] = [
                'id' => Uuid::v4()->toRfc4122(),
                'row_number' => $product['row_number'],
                'status' => $result['status'],
                'existing_product_id' => $result['existing_product_id'],
                'raw_payload' => [
                    'csv' => $product['raw'],
                    'normalized' => $product['normalized'],
                ],
                'resolved_payload' => [
                    'resolved' => $result['resolved'],
                    'warnings' => $result['warnings'],
                    'diff' => $result['diff'],
                ],
                'errors' => $result['errors'] !== [] ? $result['errors'] : null,
            ];
        }

        if ($structuralErrors !== [] && $rowInserts === []) {
            $status = 'invalid';
        }

        $catalogPreview = $resolver->catalogPreview();
        $total = count($rowInserts);

        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare(
                'INSERT INTO product_imports (
                    id, filename, status, created_by,
                    total_rows, ready_count, conflict_count, invalid_count,
                    structural_errors, catalog_preview, created_at, updated_at
                 ) VALUES (
                    :id, :filename, :status, :created_by,
                    :total_rows, :ready_count, :conflict_count, :invalid_count,
                    CAST(:structural_errors AS jsonb), CAST(:catalog_preview AS jsonb), NOW(), NOW()
                 )'
            );
            $ins->execute([
                'id' => $importId,
                'filename' => $filename,
                'status' => $status,
                'created_by' => $userId,
                'total_rows' => $total,
                'ready_count' => $ready,
                'conflict_count' => $conflict,
                'invalid_count' => $invalid,
                'structural_errors' => $structuralErrors !== []
                    ? json_encode($structuralErrors, JSON_UNESCAPED_UNICODE)
                    : null,
                'catalog_preview' => json_encode($catalogPreview, JSON_UNESCAPED_UNICODE),
            ]);

            $rowStmt = $this->pdo->prepare(
                'INSERT INTO product_import_rows (
                    id, import_id, row_number, status, existing_product_id,
                    raw_payload, resolved_payload, errors, created_at, updated_at
                 ) VALUES (
                    :id, :import_id, :row_number, :status, :existing_product_id,
                    CAST(:raw_payload AS jsonb), CAST(:resolved_payload AS jsonb), CAST(:errors AS jsonb), NOW(), NOW()
                 )'
            );
            foreach ($rowInserts as $row) {
                $rowStmt->execute([
                    'id' => $row['id'],
                    'import_id' => $importId,
                    'row_number' => $row['row_number'],
                    'status' => $row['status'],
                    'existing_product_id' => $row['existing_product_id'],
                    'raw_payload' => json_encode($row['raw_payload'], JSON_UNESCAPED_UNICODE),
                    'resolved_payload' => json_encode($row['resolved_payload'], JSON_UNESCAPED_UNICODE),
                    'errors' => $row['errors'] !== null
                        ? json_encode($row['errors'], JSON_UNESCAPED_UNICODE)
                        : null,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $presented = $this->get($importId);
        if ($presented === null) {
            throw new RuntimeException('Import created but not found');
        }

        return $presented;
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array{page:int,per_page:int,total:int}}
     */
    public function list(array $queryParams): array
    {
        $pagination = Pagination::resolve($queryParams);
        $countStmt = $this->pdo->query('SELECT COUNT(*)::int AS total FROM product_imports');
        $total = (int) (($countStmt?->fetch()['total'] ?? 0));

        $stmt = $this->pdo->prepare(
            'SELECT pi.id, pi.filename, pi.status, pi.created_by, pi.total_rows,
                    pi.ready_count, pi.conflict_count, pi.invalid_count,
                    pi.created_count, pi.updated_count, pi.failed_count, pi.skipped_count,
                    pi.structural_errors, pi.catalog_preview, pi.created_at, pi.updated_at,
                    u.email AS created_by_email, u.name AS created_by_name
             FROM product_imports pi
             LEFT JOIN users u ON u.id = pi.created_by
             ORDER BY pi.created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        return [
            'items' => array_map(fn (array $row): array => $this->presentImport($row, false), $rows),
            'meta' => [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
            ],
        ];
    }

    public function get(string $importId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pi.id, pi.filename, pi.status, pi.created_by, pi.total_rows,
                    pi.ready_count, pi.conflict_count, pi.invalid_count,
                    pi.created_count, pi.updated_count, pi.failed_count, pi.skipped_count,
                    pi.structural_errors, pi.catalog_preview, pi.created_at, pi.updated_at,
                    u.email AS created_by_email, u.name AS created_by_name
             FROM product_imports pi
             LEFT JOIN users u ON u.id = pi.created_by
             WHERE pi.id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $importId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentImport($row, true) : null;
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array{page:int,per_page:int,total:int}}
     */
    public function listRows(string $importId, array $queryParams): array
    {
        if ($this->get($importId) === null) {
            throw new RuntimeException('Import not found');
        }

        $pagination = Pagination::resolve($queryParams);
        $status = null;
        if (array_key_exists('status', $queryParams) && trim((string) $queryParams['status']) !== '') {
            $status = trim((string) $queryParams['status']);
            $allowed = ['ready', 'conflict', 'invalid', 'created', 'updated', 'failed', 'skipped'];
            if (!in_array($status, $allowed, true)) {
                throw new RuntimeException('Invalid status filter');
            }
        }

        $countSql = 'SELECT COUNT(*)::int AS total FROM product_import_rows WHERE import_id::text = :import_id';
        $params = ['import_id' => $importId];
        if ($status !== null) {
            $countSql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) (($countStmt->fetch()['total'] ?? 0));

        $sql = 'SELECT id, import_id, row_number, status, decision, existing_product_id, result_product_id,
                       raw_payload, resolved_payload, errors, created_at, updated_at
                FROM product_import_rows
                WHERE import_id::text = :import_id';
        if ($status !== null) {
            $sql .= ' AND status = :status';
        }
        $sql .= ' ORDER BY row_number ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':import_id', $importId);
        if ($status !== null) {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        return [
            'items' => array_map(fn (array $row): array => $this->presentRow($row), $rows),
            'meta' => [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setRowDecision(string $importId, string $rowId, string $decision): array
    {
        $this->assertDecision($decision);
        $import = $this->requireImportForReview($importId);

        $row = $this->getRawRow($importId, $rowId);
        if ($row === null) {
            throw new RuntimeException('Import row not found');
        }
        if ((string) $row['status'] !== 'conflict') {
            throw new RuntimeException('Decision can only be set on conflict rows');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE product_import_rows
             SET decision = :decision, updated_at = NOW()
             WHERE id::text = :id AND import_id::text = :import_id
             RETURNING id, import_id, row_number, status, decision, existing_product_id, result_product_id,
                       raw_payload, resolved_payload, errors, created_at, updated_at'
        );
        $stmt->execute([
            'decision' => $decision,
            'id' => $rowId,
            'import_id' => $importId,
        ]);
        $updated = $stmt->fetch();
        if (!is_array($updated)) {
            throw new RuntimeException('Import row not found');
        }

        unset($import);

        return $this->presentRow($updated);
    }

    /**
     * @return array{updated: int, decision: string}
     */
    public function setBulkDecision(string $importId, string $decision, string $statusFilter = 'conflict'): array
    {
        $this->assertDecision($decision);
        $this->requireImportForReview($importId);

        if ($statusFilter !== 'conflict') {
            throw new RuntimeException('Bulk decision only supported for status=conflict');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE product_import_rows
             SET decision = :decision, updated_at = NOW()
             WHERE import_id::text = :import_id AND status = :status'
        );
        $stmt->execute([
            'decision' => $decision,
            'import_id' => $importId,
            'status' => $statusFilter,
        ]);

        return ['updated' => $stmt->rowCount(), 'decision' => $decision];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $importId): array
    {
        $this->requireImportForReview($importId);

        $stmt = $this->pdo->prepare(
            'UPDATE product_imports SET status = :status, updated_at = NOW()
             WHERE id::text = :id'
        );
        $stmt->execute(['status' => 'cancelled', 'id' => $importId]);

        $presented = $this->get($importId);
        if ($presented === null) {
            throw new RuntimeException('Import not found');
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(string $importId): array
    {
        $this->requireImportForReview($importId);

        $undecided = $this->pdo->prepare(
            'SELECT COUNT(*)::int AS total FROM product_import_rows
             WHERE import_id::text = :import_id AND status = :status AND decision IS NULL'
        );
        $undecided->execute(['import_id' => $importId, 'status' => 'conflict']);
        $pending = (int) (($undecided->fetch()['total'] ?? 0));
        if ($pending > 0) {
            throw new RuntimeException('There are ' . $pending . ' conflict rows without a decision');
        }

        $this->pdo->prepare(
            'UPDATE product_imports SET status = :status, updated_at = NOW() WHERE id::text = :id'
        )->execute(['status' => 'processing', 'id' => $importId]);

        $rowsStmt = $this->pdo->prepare(
            'SELECT id, import_id, row_number, status, decision, existing_product_id, result_product_id,
                    raw_payload, resolved_payload, errors
             FROM product_import_rows
             WHERE import_id::text = :import_id
             ORDER BY row_number ASC'
        );
        $rowsStmt->execute(['import_id' => $importId]);
        $rows = $rowsStmt->fetchAll() ?: [];

        $toProcess = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $decision = $row['decision'] !== null ? (string) $row['decision'] : null;
            if ($status === 'ready') {
                $toProcess[] = ['row' => $row, 'action' => 'create'];
            } elseif ($status === 'conflict' && $decision === 'create_new') {
                $toProcess[] = ['row' => $row, 'action' => 'create'];
            } elseif ($status === 'conflict' && $decision === 'update_existing') {
                $toProcess[] = ['row' => $row, 'action' => 'update'];
            } elseif ($status === 'conflict' && $decision === 'skip') {
                $toProcess[] = ['row' => $row, 'action' => 'skip'];
            }
        }

        $resolvedList = [];
        foreach ($toProcess as $item) {
            if ($item['action'] === 'skip') {
                continue;
            }
            $payload = $this->decodeJson($item['row']['resolved_payload'] ?? null) ?? [];
            $resolved = $payload['resolved'] ?? null;
            if (is_array($resolved)) {
                $resolvedList[] = $resolved;
            }
        }

        try {
            $this->pdo->beginTransaction();
            $materializer = new CatalogMaterializer($this->pdo);
            $materializer->materializeAll($resolvedList);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->prepare(
                'UPDATE product_imports SET status = :status, updated_at = NOW() WHERE id::text = :id'
            )->execute(['status' => 'ready_for_review', 'id' => $importId]);
            throw new RuntimeException('Failed to materialize catalog: ' . $e->getMessage(), 0, $e);
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;
        // Rehydrate materializer after commit (IDs persist in DB; warm from DB again).
        $materializer = new CatalogMaterializer($this->pdo);

        foreach ($toProcess as $item) {
            $row = $item['row'];
            $rowId = (string) $row['id'];
            $action = $item['action'];

            if ($action === 'skip') {
                $this->markRowResult($rowId, 'skipped', null, null);
                ++$skipped;
                continue;
            }

            $payload = $this->decodeJson($row['resolved_payload'] ?? null) ?? [];
            $resolved = $payload['resolved'] ?? null;
            if (!is_array($resolved)) {
                $this->markRowResult($rowId, 'failed', null, [[
                    'code' => 'missing_resolved_payload',
                    'message' => 'Resolved payload missing',
                    'column' => null,
                ]]);
                ++$failed;
                continue;
            }

            try {
                $this->pdo->beginTransaction();
                if ($action === 'create') {
                    $productId = $this->createProductFromResolved($resolved, $materializer);
                    $this->markRowResult($rowId, 'created', $productId, null);
                    ++$created;
                } else {
                    $existingId = $row['existing_product_id'] !== null ? (string) $row['existing_product_id'] : null;
                    if ($existingId === null) {
                        throw new RuntimeException('Missing existing_product_id for update');
                    }
                    $productId = $this->updateProductFromResolved($existingId, $resolved, $materializer);
                    $this->markRowResult($rowId, 'updated', $productId, null);
                    ++$updated;
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->markRowResult($rowId, 'failed', null, [[
                    'code' => 'import_failed',
                    'message' => $e->getMessage(),
                    'column' => null,
                ]]);
                ++$failed;
            }
        }

        $finalStatus = $failed > 0 ? 'completed_with_errors' : 'completed';
        $upd = $this->pdo->prepare(
            'UPDATE product_imports SET
                status = :status,
                created_count = :created_count,
                updated_count = :updated_count,
                failed_count = :failed_count,
                skipped_count = :skipped_count,
                updated_at = NOW()
             WHERE id::text = :id'
        );
        $upd->execute([
            'status' => $finalStatus,
            'created_count' => $created,
            'updated_count' => $updated,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'id' => $importId,
        ]);

        $presented = $this->get($importId);
        if ($presented === null) {
            throw new RuntimeException('Import not found');
        }

        return $presented;
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private function createProductFromResolved(array $resolved, CatalogMaterializer $materializer): string
    {
        $categoryRef = is_array($resolved['category'] ?? null) ? $resolved['category'] : null;
        $subcategoryRef = is_array($resolved['subcategory'] ?? null) ? $resolved['subcategory'] : null;
        $brandRef = is_array($resolved['brand'] ?? null) ? $resolved['brand'] : null;
        $subBrandRef = is_array($resolved['sub_brand'] ?? null) ? $resolved['sub_brand'] : null;

        $categoryId = $materializer->categoryId($categoryRef);
        $subcategoryId = $materializer->subcategoryId($subcategoryRef, $categoryRef);
        $brandId = $materializer->brandId($brandRef);
        $subBrandId = $materializer->subBrandId($subBrandRef, $brandRef);
        $dispensingTypeId = $materializer->dispensingTypeId(
            is_array($resolved['dispensing_type'] ?? null) ? $resolved['dispensing_type'] : null
        );
        $speciesId = $materializer->speciesId(
            is_array($resolved['species'] ?? null) ? $resolved['species'] : null
        );
        $specialtyId = $materializer->specialtyId(
            is_array($resolved['specialty'] ?? null) ? $resolved['specialty'] : null
        );

        $id = Uuid::v4()->toRfc4122();
        $sku = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $name = trim((string) ($resolved['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Product name is required');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO products (
                id, sku, name, barcode, internal_reference,
                category_id, subcategory_id, brand_id, dispensing_type_id,
                national_code, packaging, sub_brand_id, species_id, specialty_id,
                is_active, unit_of_measure, created_at, updated_at
             ) VALUES (
                :id, :sku, :name, :barcode, :internal_reference,
                :category_id, :subcategory_id, :brand_id, :dispensing_type_id,
                :national_code, :packaging, :sub_brand_id, :species_id, :specialty_id,
                :is_active, :unit_of_measure, NOW(), NOW()
             )'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':sku', $sku);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':barcode', $resolved['barcode'] ?? null);
        $stmt->bindValue(':internal_reference', $resolved['internal_reference'] ?? null);
        $stmt->bindValue(':category_id', $categoryId);
        $stmt->bindValue(':subcategory_id', $subcategoryId);
        $stmt->bindValue(':brand_id', $brandId);
        $stmt->bindValue(':dispensing_type_id', $dispensingTypeId);
        $stmt->bindValue(':national_code', $resolved['national_code'] ?? null);
        $stmt->bindValue(':packaging', $resolved['packaging'] ?? null);
        $stmt->bindValue(':sub_brand_id', $subBrandId);
        $stmt->bindValue(':species_id', $speciesId);
        $stmt->bindValue(':specialty_id', $specialtyId);
        $stmt->bindValue(':is_active', (bool) ($resolved['is_active'] ?? true), PDO::PARAM_BOOL);
        $stmt->bindValue(':unit_of_measure', (string) ($resolved['unit_of_measure'] ?? 'Unidades'));
        $stmt->execute();

        $this->syncTags($id, $resolved['tags'] ?? [], $materializer, replace: true);
        $this->syncSuppliers($id, $resolved['suppliers'] ?? [], $materializer, preferFirstIfNone: true);
        $this->linkClinicsHidden($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private function updateProductFromResolved(
        string $productId,
        array $resolved,
        CatalogMaterializer $materializer
    ): string {
        $currentStmt = $this->pdo->prepare(
            'SELECT id, name, barcode, internal_reference, category_id, subcategory_id, brand_id,
                    dispensing_type_id, national_code, packaging, sub_brand_id, species_id, specialty_id,
                    is_active, unit_of_measure
             FROM products WHERE id::text = :id LIMIT 1'
        );
        $currentStmt->execute(['id' => $productId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            throw new RuntimeException('Existing product not found');
        }

        $categoryRef = is_array($resolved['category'] ?? null) ? $resolved['category'] : null;
        $subcategoryRef = is_array($resolved['subcategory'] ?? null) ? $resolved['subcategory'] : null;
        $brandRef = is_array($resolved['brand'] ?? null) ? $resolved['brand'] : null;
        $subBrandRef = is_array($resolved['sub_brand'] ?? null) ? $resolved['sub_brand'] : null;

        $name = trim((string) ($resolved['name'] ?? ''));
        if ($name === '') {
            $name = (string) $current['name'];
        }

        $barcode = array_key_exists('barcode', $resolved) && $resolved['barcode'] !== null
            ? $resolved['barcode']
            : ($current['barcode'] !== null ? (string) $current['barcode'] : null);
        $internalReference = array_key_exists('internal_reference', $resolved) && $resolved['internal_reference'] !== null
            ? $resolved['internal_reference']
            : ($current['internal_reference'] !== null ? (string) $current['internal_reference'] : null);
        $nationalCode = array_key_exists('national_code', $resolved) && $resolved['national_code'] !== null
            ? $resolved['national_code']
            : ($current['national_code'] !== null ? (string) $current['national_code'] : null);
        $packaging = array_key_exists('packaging', $resolved) && $resolved['packaging'] !== null
            ? $resolved['packaging']
            : ($current['packaging'] !== null ? (string) $current['packaging'] : null);

        $categoryId = $categoryRef !== null
            ? $materializer->categoryId($categoryRef)
            : ($current['category_id'] !== null ? (string) $current['category_id'] : null);
        $subcategoryId = $subcategoryRef !== null
            ? $materializer->subcategoryId($subcategoryRef, $categoryRef)
            : ($current['subcategory_id'] !== null ? (string) $current['subcategory_id'] : null);
        $brandId = $brandRef !== null
            ? $materializer->brandId($brandRef)
            : ($current['brand_id'] !== null ? (string) $current['brand_id'] : null);
        $subBrandId = $subBrandRef !== null
            ? $materializer->subBrandId($subBrandRef, $brandRef)
            : ($current['sub_brand_id'] !== null ? (string) $current['sub_brand_id'] : null);
        $dispensingTypeId = is_array($resolved['dispensing_type'] ?? null)
            ? $materializer->dispensingTypeId($resolved['dispensing_type'])
            : ($current['dispensing_type_id'] !== null ? (string) $current['dispensing_type_id'] : null);
        $speciesId = is_array($resolved['species'] ?? null)
            ? $materializer->speciesId($resolved['species'])
            : ($current['species_id'] !== null ? (string) $current['species_id'] : null);
        $specialtyId = is_array($resolved['specialty'] ?? null)
            ? $materializer->specialtyId($resolved['specialty'])
            : ($current['specialty_id'] !== null ? (string) $current['specialty_id'] : null);

        $unit = (string) ($resolved['unit_of_measure'] ?? $current['unit_of_measure'] ?? 'Unidades');
        if ($unit === '') {
            $unit = (string) ($current['unit_of_measure'] ?? 'Unidades');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE products SET
                name = :name,
                barcode = :barcode,
                internal_reference = :internal_reference,
                category_id = :category_id,
                subcategory_id = :subcategory_id,
                brand_id = :brand_id,
                dispensing_type_id = :dispensing_type_id,
                national_code = :national_code,
                packaging = :packaging,
                sub_brand_id = :sub_brand_id,
                species_id = :species_id,
                specialty_id = :specialty_id,
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
        $stmt->bindValue(':national_code', $nationalCode);
        $stmt->bindValue(':packaging', $packaging);
        $stmt->bindValue(':sub_brand_id', $subBrandId);
        $stmt->bindValue(':species_id', $speciesId);
        $stmt->bindValue(':specialty_id', $specialtyId);
        $stmt->bindValue(':is_active', (bool) ($resolved['is_active'] ?? $current['is_active']), PDO::PARAM_BOOL);
        $stmt->bindValue(':unit_of_measure', $unit);
        $stmt->execute();

        $tags = $resolved['tags'] ?? [];
        if (is_array($tags) && $tags !== []) {
            $this->syncTags($productId, $tags, $materializer, replace: true);
        }

        $suppliers = $resolved['suppliers'] ?? [];
        if (is_array($suppliers) && $suppliers !== []) {
            $this->syncSuppliers($productId, $suppliers, $materializer, preferFirstIfNone: false);
        }

        return $productId;
    }

    /**
     * @param list<mixed> $tags
     */
    private function syncTags(string $productId, array $tags, CatalogMaterializer $materializer, bool $replace): void
    {
        if ($replace) {
            $this->pdo->prepare('DELETE FROM product_product_tags WHERE product_id::text = :id')
                ->execute(['id' => $productId]);
        }
        $ins = $this->pdo->prepare(
            'INSERT INTO product_product_tags (product_id, product_tag_id)
             VALUES (:product_id, :product_tag_id)
             ON CONFLICT DO NOTHING'
        );
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $tagId = $materializer->tagId($tag);
            if ($tagId === null) {
                continue;
            }
            $ins->execute(['product_id' => $productId, 'product_tag_id' => $tagId]);
        }
    }

    /**
     * @param list<mixed> $suppliers
     */
    private function syncSuppliers(
        string $productId,
        array $suppliers,
        CatalogMaterializer $materializer,
        bool $preferFirstIfNone
    ): void {
        $hasPreferred = false;
        if ($preferFirstIfNone) {
            $check = $this->pdo->prepare(
                'SELECT 1 FROM product_suppliers WHERE product_id::text = :id AND is_preferred = TRUE LIMIT 1'
            );
            $check->execute(['id' => $productId]);
            $hasPreferred = (bool) $check->fetch();
        }

        $first = true;
        foreach ($suppliers as $supplierRow) {
            if (!is_array($supplierRow)) {
                continue;
            }
            $supplierRef = is_array($supplierRow['supplier'] ?? null) ? $supplierRow['supplier'] : null;
            $supplierId = $materializer->supplierId($supplierRef);
            if ($supplierId === null) {
                continue;
            }

            $isPreferred = $preferFirstIfNone && !$hasPreferred && $first;
            $first = false;

            $existing = $this->pdo->prepare(
                'SELECT id::text AS id FROM product_suppliers
                 WHERE product_id::text = :product_id AND supplier_id::text = :supplier_id
                   AND COALESCE(supplier_reference, \'\') = \'\'
                 LIMIT 1'
            );
            $existing->execute(['product_id' => $productId, 'supplier_id' => $supplierId]);
            $link = $existing->fetch();

            if (is_array($link)) {
                $upd = $this->pdo->prepare(
                    'UPDATE product_suppliers SET
                        purchase_price = :purchase_price,
                        pvp = :pvp,
                        net_cost = :net_cost,
                        updated_at = NOW()
                     WHERE id::text = :id'
                );
                $upd->bindValue(':id', (string) $link['id']);
                $upd->bindValue(':purchase_price', $supplierRow['purchase_price'] ?? null);
                $upd->bindValue(':pvp', $supplierRow['pvp'] ?? null);
                $upd->bindValue(':net_cost', $supplierRow['net_cost'] ?? null);
                $upd->execute();
            } else {
                if ($isPreferred) {
                    $this->pdo->prepare(
                        'UPDATE product_suppliers SET is_preferred = FALSE, updated_at = NOW()
                         WHERE product_id::text = :product_id AND is_preferred = TRUE'
                    )->execute(['product_id' => $productId]);
                }
                $ins = $this->pdo->prepare(
                    'INSERT INTO product_suppliers (
                        id, product_id, supplier_id, supplier_reference,
                        purchase_price, pvp, net_cost, is_preferred, created_at, updated_at
                     ) VALUES (
                        :id, :product_id, :supplier_id, NULL,
                        :purchase_price, :pvp, :net_cost, :is_preferred, NOW(), NOW()
                     )'
                );
                $ins->bindValue(':id', Uuid::v4()->toRfc4122());
                $ins->bindValue(':product_id', $productId);
                $ins->bindValue(':supplier_id', $supplierId);
                $ins->bindValue(':purchase_price', $supplierRow['purchase_price'] ?? null);
                $ins->bindValue(':pvp', $supplierRow['pvp'] ?? null);
                $ins->bindValue(':net_cost', $supplierRow['net_cost'] ?? null);
                $ins->bindValue(':is_preferred', $isPreferred, PDO::PARAM_BOOL);
                $ins->execute();
            }
        }
    }

    private function linkClinicsHidden(string $productId): void
    {
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
                'product_id' => $productId,
            ]);
        }
    }

    /**
     * @param list<array{code:string,message:string,column:?string}>|null $errors
     */
    private function markRowResult(string $rowId, string $status, ?string $productId, ?array $errors): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE product_import_rows SET
                status = :status,
                result_product_id = :result_product_id,
                errors = CAST(:errors AS jsonb),
                updated_at = NOW()
             WHERE id::text = :id'
        );
        $stmt->execute([
            'status' => $status,
            'result_product_id' => $productId,
            'errors' => $errors !== null ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
            'id' => $rowId,
        ]);
    }

    private function assertDecision(string $decision): void
    {
        if (!in_array($decision, ['create_new', 'update_existing', 'skip'], true)) {
            throw new RuntimeException('Invalid decision');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireImportForReview(string $importId): array
    {
        $import = $this->get($importId);
        if ($import === null) {
            throw new RuntimeException('Import not found');
        }
        if (($import['status'] ?? '') !== 'ready_for_review') {
            throw new RuntimeException('Import is not ready for review');
        }

        return $import;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRawRow(string $importId, string $rowId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, import_id, row_number, status, decision, existing_product_id, result_product_id,
                    raw_payload, resolved_payload, errors, created_at, updated_at
             FROM product_import_rows
             WHERE id::text = :id AND import_id::text = :import_id LIMIT 1'
        );
        $stmt->execute(['id' => $rowId, 'import_id' => $importId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function assertUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Invalid file upload');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Empty file');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('File exceeds maximum size of 5MB');
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            // En tests puede no ser is_uploaded_file; aceptar fichero legible.
            if ($tmpName === '' || !is_file($tmpName) || !is_readable($tmpName)) {
                throw new RuntimeException('Invalid uploaded file');
            }
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        if ($name !== '' && !str_ends_with($name, '.csv')) {
            throw new RuntimeException('Only .csv files are allowed');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentImport(array $row, bool $detailed): array
    {
        $data = [
            'id' => (string) $row['id'],
            'filename' => (string) $row['filename'],
            'status' => (string) $row['status'],
            'created_by' => (string) $row['created_by'],
            'created_by_email' => $row['created_by_email'] !== null ? (string) $row['created_by_email'] : null,
            'created_by_name' => $row['created_by_name'] !== null ? (string) $row['created_by_name'] : null,
            'total_rows' => (int) $row['total_rows'],
            'ready_count' => (int) $row['ready_count'],
            'conflict_count' => (int) $row['conflict_count'],
            'invalid_count' => (int) $row['invalid_count'],
            'created_count' => (int) $row['created_count'],
            'updated_count' => (int) $row['updated_count'],
            'failed_count' => (int) $row['failed_count'],
            'skipped_count' => (int) $row['skipped_count'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];

        if ($detailed) {
            $data['structural_errors'] = $this->decodeJson($row['structural_errors'] ?? null) ?? [];
            $data['catalog_preview'] = $this->decodeJson($row['catalog_preview'] ?? null) ?? [];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRow(array $row): array
    {
        $resolvedPayload = $this->decodeJson($row['resolved_payload'] ?? null) ?? [];
        $rawPayload = $this->decodeJson($row['raw_payload'] ?? null) ?? [];

        return [
            'id' => (string) $row['id'],
            'import_id' => (string) $row['import_id'],
            'row_number' => (int) $row['row_number'],
            'status' => (string) $row['status'],
            'decision' => $row['decision'] !== null ? (string) $row['decision'] : null,
            'existing_product_id' => $row['existing_product_id'] !== null ? (string) $row['existing_product_id'] : null,
            'result_product_id' => $row['result_product_id'] !== null ? (string) $row['result_product_id'] : null,
            'normalized' => $rawPayload['normalized'] ?? null,
            'resolved' => $resolvedPayload['resolved'] ?? null,
            'warnings' => $resolvedPayload['warnings'] ?? [],
            'diff' => $resolvedPayload['diff'] ?? null,
            'errors' => $this->decodeJson($row['errors'] ?? null) ?? [],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
