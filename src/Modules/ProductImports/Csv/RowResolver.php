<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Csv;

use App\Application\Support\Slug;
use PDO;

final class RowResolver
{
    /** @var array<string, array{id:string,name:string}> */
    private array $categoriesBySlug = [];

    /** @var array<string, array{id:string,name:string,category_id:string}> */
    private array $subcategoriesByKey = [];

    /** @var array<string, array{id:string,name:string}> */
    private array $brandsBySlug = [];

    /** @var array<string, array{id:string,name:string,brand_id:string}> */
    private array $subBrandsByKey = [];

    /** @var array<string, array{id:string,name:string}> */
    private array $dispensingTypesBySlug = [];

    /** @var array<string, array{id:string,name:string}> */
    private array $speciesBySlug = [];

    /** @var array<string, array{id:string,name:string}> */
    private array $specialtiesBySlug = [];

    /** @var array<string, array{id:string,name:string}> */
    private array $tagsBySlug = [];

    /** @var array<string, array{id:string,name:string}> */
    private array $suppliersBySlug = [];

    /** @var array<string, string> internal_reference => product_id */
    private array $productsByInternalRef = [];

    /** @var array<string, string> barcode => product_id */
    private array $productsByBarcode = [];

    /** @var array<string, string> national_code => product_id */
    private array $productsByNationalCode = [];

    /** @var array<string, true> */
    private array $pendingCategories = [];

    /** @var array<string, true> key categorySlug|subSlug */
    private array $pendingSubcategories = [];

    /** @var array<string, true> */
    private array $pendingBrands = [];

    /** @var array<string, true> */
    private array $pendingSubBrands = [];

    /** @var array<string, true> */
    private array $pendingDispensingTypes = [];

    /** @var array<string, true> */
    private array $pendingSpecies = [];

    /** @var array<string, true> */
    private array $pendingSpecialties = [];

    /** @var array<string, true> */
    private array $pendingTags = [];

    /** @var array<string, true> */
    private array $pendingSuppliers = [];

    /** @var array<string, int> internal_reference => first row_number in file */
    private array $seenInternalRefsInFile = [];

    public function __construct(private readonly PDO $pdo)
    {
        $this->warmCaches();
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{
     *     status: string,
     *     existing_product_id: ?string,
     *     errors: list<array{code:string,message:string,column:?string}>,
     *     warnings: list<array{code:string,message:string,column:?string}>,
     *     resolved: array<string, mixed>,
     *     diff: ?array<string, array{current:mixed,incoming:mixed}>
     * }
     */
    public function resolve(array $normalized, int $rowNumber): array
    {
        $errors = [];
        $warnings = [];

        $name = $normalized['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $errors[] = [
                'code' => 'missing_name',
                'message' => 'Product name is required',
                'column' => ExpectedHeaders::NAME,
            ];
        }

        $type = $normalized['type'] ?? null;
        if (is_string($type) && $type !== '' && strcasecmp($type, 'Almacenable') !== 0) {
            $warnings[] = [
                'code' => 'unexpected_product_type',
                'message' => 'Product type is "' . $type . '"; only Almacenable is expected',
                'column' => ExpectedHeaders::TYPE,
            ];
        }

        $internalRef = is_string($normalized['internal_reference'] ?? null)
            ? (string) $normalized['internal_reference']
            : null;
        if ($internalRef !== null) {
            if (isset($this->seenInternalRefsInFile[$internalRef])) {
                $errors[] = [
                    'code' => 'duplicate_internal_reference_in_file',
                    'message' => 'Duplicate internal reference in file (first seen at row '
                        . $this->seenInternalRefsInFile[$internalRef] . ')',
                    'column' => ExpectedHeaders::INTERNAL_REFERENCE,
                ];
            } else {
                $this->seenInternalRefsInFile[$internalRef] = $rowNumber;
            }
        }

        $barcode = is_string($normalized['barcode'] ?? null) ? (string) $normalized['barcode'] : null;
        $nationalCode = is_string($normalized['national_code'] ?? null) ? (string) $normalized['national_code'] : null;

        $category = $this->resolveSimple(
            is_string($normalized['category'] ?? null) ? (string) $normalized['category'] : null,
            $this->categoriesBySlug,
            $this->pendingCategories,
            'category'
        );
        $brand = $this->resolveSimple(
            is_string($normalized['brand'] ?? null) ? (string) $normalized['brand'] : null,
            $this->brandsBySlug,
            $this->pendingBrands,
            'brand'
        );
        $dispensingType = $this->resolveSimple(
            is_string($normalized['dispensing_type'] ?? null) ? (string) $normalized['dispensing_type'] : null,
            $this->dispensingTypesBySlug,
            $this->pendingDispensingTypes,
            'dispensing_type'
        );
        $species = $this->resolveSimple(
            is_string($normalized['species'] ?? null) ? (string) $normalized['species'] : null,
            $this->speciesBySlug,
            $this->pendingSpecies,
            'species'
        );
        $specialty = $this->resolveSimple(
            is_string($normalized['specialty'] ?? null) ? (string) $normalized['specialty'] : null,
            $this->specialtiesBySlug,
            $this->pendingSpecialties,
            'specialty'
        );

        $subcategory = $this->resolveChild(
            is_string($normalized['subcategory'] ?? null) ? (string) $normalized['subcategory'] : null,
            $category,
            $this->subcategoriesByKey,
            $this->pendingSubcategories,
            'subcategory',
            'category'
        );
        if ($subcategory['error'] !== null) {
            $errors[] = $subcategory['error'];
        }

        $subBrand = $this->resolveChild(
            is_string($normalized['sub_brand'] ?? null) ? (string) $normalized['sub_brand'] : null,
            $brand,
            $this->subBrandsByKey,
            $this->pendingSubBrands,
            'sub_brand',
            'brand'
        );
        if ($subBrand['error'] !== null) {
            $errors[] = $subBrand['error'];
        }

        $tags = [];
        foreach ($normalized['tags'] ?? [] as $tagName) {
            if (!is_string($tagName) || $tagName === '') {
                continue;
            }
            $tags[] = $this->resolveSimple($tagName, $this->tagsBySlug, $this->pendingTags, 'tag');
        }

        $suppliers = [];
        foreach ($normalized['suppliers'] ?? [] as $supplierRow) {
            if (!is_array($supplierRow)) {
                continue;
            }
            $vendor = is_string($supplierRow['vendor'] ?? null) ? (string) $supplierRow['vendor'] : null;
            if ($vendor === null) {
                $errors[] = [
                    'code' => 'missing_supplier_vendor',
                    'message' => 'Supplier vendor name is required when supplier columns are present',
                    'column' => ExpectedHeaders::SUPPLIER_VENDOR,
                ];
                continue;
            }
            $resolvedSupplier = $this->resolveSimple($vendor, $this->suppliersBySlug, $this->pendingSuppliers, 'supplier');
            $suppliers[] = [
                'supplier' => $resolvedSupplier,
                'purchase_price' => $supplierRow['purchase_price'] ?? null,
                'pvp' => $supplierRow['pvp'] ?? null,
                'net_cost' => $supplierRow['net_cost'] ?? null,
            ];
        }

        $existingProductId = $internalRef !== null
            ? ($this->productsByInternalRef[$internalRef] ?? null)
            : null;

        if ($barcode !== null && isset($this->productsByBarcode[$barcode])) {
            $ownerId = $this->productsByBarcode[$barcode];
            if ($existingProductId === null || $ownerId !== $existingProductId) {
                $errors[] = [
                    'code' => 'barcode_already_exists',
                    'message' => 'Barcode already belongs to another product',
                    'column' => ExpectedHeaders::BARCODE,
                ];
            }
        }

        if ($nationalCode !== null && isset($this->productsByNationalCode[$nationalCode])) {
            $ownerId = $this->productsByNationalCode[$nationalCode];
            if ($existingProductId === null || $ownerId !== $existingProductId) {
                $errors[] = [
                    'code' => 'national_code_already_exists',
                    'message' => 'National code already belongs to another product',
                    'column' => ExpectedHeaders::NATIONAL_CODE,
                ];
            }
        }

        $resolved = [
            'name' => $name,
            'is_active' => (bool) ($normalized['is_active'] ?? true),
            'barcode' => $barcode,
            'internal_reference' => $internalRef,
            'unit_of_measure' => $normalized['unit_of_measure'] ?? 'Unidades',
            'packaging' => $normalized['packaging'] ?? null,
            'national_code' => $nationalCode,
            'category' => $category,
            'subcategory' => $subcategory['resolved'],
            'brand' => $brand,
            'sub_brand' => $subBrand['resolved'],
            'dispensing_type' => $dispensingType,
            'species' => $species,
            'specialty' => $specialty,
            'tags' => $tags,
            'suppliers' => $suppliers,
        ];

        if ($errors !== []) {
            return [
                'status' => 'invalid',
                'existing_product_id' => $existingProductId,
                'errors' => $errors,
                'warnings' => $warnings,
                'resolved' => $resolved,
                'diff' => null,
            ];
        }

        if ($existingProductId !== null) {
            return [
                'status' => 'conflict',
                'existing_product_id' => $existingProductId,
                'errors' => [],
                'warnings' => $warnings,
                'resolved' => $resolved,
                'diff' => $this->buildDiff($existingProductId, $resolved),
            ];
        }

        return [
            'status' => 'ready',
            'existing_product_id' => null,
            'errors' => [],
            'warnings' => $warnings,
            'resolved' => $resolved,
            'diff' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogPreview(): array
    {
        return [
            'categories' => array_keys($this->pendingCategories),
            'subcategories' => array_keys($this->pendingSubcategories),
            'brands' => array_keys($this->pendingBrands),
            'sub_brands' => array_keys($this->pendingSubBrands),
            'dispensing_types' => array_keys($this->pendingDispensingTypes),
            'species' => array_keys($this->pendingSpecies),
            'specialties' => array_keys($this->pendingSpecialties),
            'tags' => array_keys($this->pendingTags),
            'suppliers' => array_keys($this->pendingSuppliers),
        ];
    }

    /**
     * @param array<string, array{id:string,name:string}> $cache
     * @param array<string, true> $pending
     * @return array{id:?string,name:?string,slug:?string,will_create:bool}|null
     */
    private function resolveSimple(?string $name, array &$cache, array &$pending, string $kind): ?array
    {
        if ($name === null || $name === '') {
            return null;
        }
        $slug = Slug::from($name);
        if (isset($cache[$slug])) {
            return [
                'id' => $cache[$slug]['id'],
                'name' => $cache[$slug]['name'],
                'slug' => $slug,
                'will_create' => false,
            ];
        }
        $pending[$name] = true;

        return [
            'id' => null,
            'name' => $name,
            'slug' => $slug,
            'will_create' => true,
        ];
    }

    /**
     * @param array{id:?string,name:?string,slug:?string,will_create:bool}|null $parent
     * @param array<string, array{id:string,name:string,category_id?:string,brand_id?:string}> $cache
     * @param array<string, true> $pending
     * @return array{resolved:?array{id:?string,name:?string,slug:?string,will_create:bool,parent_slug:?string},error:?array{code:string,message:string,column:?string}}
     */
    private function resolveChild(
        ?string $name,
        ?array $parent,
        array &$cache,
        array &$pending,
        string $kind,
        string $parentKind,
    ): array {
        if ($name === null || $name === '') {
            return ['resolved' => null, 'error' => null];
        }
        if ($parent === null) {
            return [
                'resolved' => null,
                'error' => [
                    'code' => $kind . '_without_' . $parentKind,
                    'message' => ucfirst(str_replace('_', '-', $kind)) . ' requires a ' . $parentKind,
                    'column' => $kind === 'subcategory' ? ExpectedHeaders::SUBCATEGORY : ExpectedHeaders::SUB_BRAND,
                ],
            ];
        }

        $parentSlug = (string) ($parent['slug'] ?? '');
        $slug = Slug::from($name);
        $key = $parentSlug . '|' . $slug;
        if (isset($cache[$key])) {
            return [
                'resolved' => [
                    'id' => $cache[$key]['id'],
                    'name' => $cache[$key]['name'],
                    'slug' => $slug,
                    'will_create' => false,
                    'parent_slug' => $parentSlug,
                ],
                'error' => null,
            ];
        }

        $pending[$parent['name'] . ' / ' . $name] = true;

        return [
            'resolved' => [
                'id' => null,
                'name' => $name,
                'slug' => $slug,
                'will_create' => true,
                'parent_slug' => $parentSlug,
            ],
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $resolved
     * @return array<string, array{current:mixed,incoming:mixed}>
     */
    private function buildDiff(string $productId, array $resolved): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name, p.is_active, p.barcode, p.internal_reference, p.unit_of_measure,
                    p.packaging, p.national_code,
                    c.name AS category_name, sc.name AS subcategory_name,
                    b.name AS brand_name, sb.name AS sub_brand_name,
                    dt.name AS dispensing_type_name, sp.name AS species_name, spt.name AS specialty_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN subcategories sc ON sc.id = p.subcategory_id
             LEFT JOIN brands b ON b.id = p.brand_id
             LEFT JOIN sub_brands sb ON sb.id = p.sub_brand_id
             LEFT JOIN dispensing_types dt ON dt.id = p.dispensing_type_id
             LEFT JOIN species sp ON sp.id = p.species_id
             LEFT JOIN specialties spt ON spt.id = p.specialty_id
             WHERE p.id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [];
        }

        $map = [
            'name' => [(string) $row['name'], $resolved['name']],
            'is_active' => [(bool) $row['is_active'], (bool) $resolved['is_active']],
            'barcode' => [$row['barcode'] !== null ? (string) $row['barcode'] : null, $resolved['barcode']],
            'internal_reference' => [
                $row['internal_reference'] !== null ? (string) $row['internal_reference'] : null,
                $resolved['internal_reference'],
            ],
            'unit_of_measure' => [(string) ($row['unit_of_measure'] ?? 'Unidades'), $resolved['unit_of_measure']],
            'packaging' => [$row['packaging'] !== null ? (string) $row['packaging'] : null, $resolved['packaging']],
            'national_code' => [
                $row['national_code'] !== null ? (string) $row['national_code'] : null,
                $resolved['national_code'],
            ],
            'category' => [
                $row['category_name'] !== null ? (string) $row['category_name'] : null,
                $resolved['category']['name'] ?? null,
            ],
            'subcategory' => [
                $row['subcategory_name'] !== null ? (string) $row['subcategory_name'] : null,
                $resolved['subcategory']['name'] ?? null,
            ],
            'brand' => [
                $row['brand_name'] !== null ? (string) $row['brand_name'] : null,
                $resolved['brand']['name'] ?? null,
            ],
            'sub_brand' => [
                $row['sub_brand_name'] !== null ? (string) $row['sub_brand_name'] : null,
                $resolved['sub_brand']['name'] ?? null,
            ],
            'dispensing_type' => [
                $row['dispensing_type_name'] !== null ? (string) $row['dispensing_type_name'] : null,
                $resolved['dispensing_type']['name'] ?? null,
            ],
            'species' => [
                $row['species_name'] !== null ? (string) $row['species_name'] : null,
                $resolved['species']['name'] ?? null,
            ],
            'specialty' => [
                $row['specialty_name'] !== null ? (string) $row['specialty_name'] : null,
                $resolved['specialty']['name'] ?? null,
            ],
        ];

        $diff = [];
        foreach ($map as $field => [$current, $incoming]) {
            if ($current !== $incoming) {
                $diff[$field] = ['current' => $current, 'incoming' => $incoming];
            }
        }

        return $diff;
    }

    private function warmCaches(): void
    {
        foreach ($this->pdo->query('SELECT id::text AS id, name, slug FROM categories') ?: [] as $row) {
            $this->categoriesBySlug[(string) $row['slug']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        foreach ($this->pdo->query(
            'SELECT sc.id::text AS id, sc.name, sc.slug, sc.category_id::text AS category_id, c.slug AS category_slug
             FROM subcategories sc INNER JOIN categories c ON c.id = sc.category_id'
        ) ?: [] as $row) {
            $key = (string) $row['category_slug'] . '|' . (string) $row['slug'];
            $this->subcategoriesByKey[$key] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'category_id' => (string) $row['category_id'],
            ];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, name, slug FROM brands') ?: [] as $row) {
            $this->brandsBySlug[(string) $row['slug']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        foreach ($this->pdo->query(
            'SELECT sb.id::text AS id, sb.name, sb.slug, sb.brand_id::text AS brand_id, b.slug AS brand_slug
             FROM sub_brands sb INNER JOIN brands b ON b.id = sb.brand_id'
        ) ?: [] as $row) {
            $key = (string) $row['brand_slug'] . '|' . (string) $row['slug'];
            $this->subBrandsByKey[$key] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'brand_id' => (string) $row['brand_id'],
            ];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, name, slug FROM dispensing_types') ?: [] as $row) {
            $this->dispensingTypesBySlug[(string) $row['slug']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, name, slug FROM species') ?: [] as $row) {
            $this->speciesBySlug[(string) $row['slug']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, name, slug FROM specialties') ?: [] as $row) {
            $this->specialtiesBySlug[(string) $row['slug']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, name, slug FROM product_tags') ?: [] as $row) {
            $this->tagsBySlug[(string) $row['slug']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, name, slug FROM suppliers') ?: [] as $row) {
            $this->suppliersBySlug[(string) $row['slug']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        foreach ($this->pdo->query(
            'SELECT id::text AS id, internal_reference, barcode, national_code FROM products'
        ) ?: [] as $row) {
            $id = (string) $row['id'];
            if ($row['internal_reference'] !== null && (string) $row['internal_reference'] !== '') {
                $this->productsByInternalRef[(string) $row['internal_reference']] = $id;
            }
            if ($row['barcode'] !== null && (string) $row['barcode'] !== '') {
                $this->productsByBarcode[(string) $row['barcode']] = $id;
            }
            if ($row['national_code'] !== null && (string) $row['national_code'] !== '') {
                $this->productsByNationalCode[(string) $row['national_code']] = $id;
            }
        }
    }
}
