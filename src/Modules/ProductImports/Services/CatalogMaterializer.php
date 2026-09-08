<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Services;

use App\Application\Support\Slug;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Find-or-create de entidades de catálogo por slug durante la confirmación de import.
 */
final class CatalogMaterializer
{
    /** @var array<string, string> slug => id */
    private array $categories = [];

    /** @var array<string, string> categorySlug|subSlug => id */
    private array $subcategories = [];

    /** @var array<string, string> */
    private array $brands = [];

    /** @var array<string, string> brandSlug|subSlug => id */
    private array $subBrands = [];

    /** @var array<string, string> */
    private array $dispensingTypes = [];

    /** @var array<string, string> */
    private array $species = [];

    /** @var array<string, string> */
    private array $specialties = [];

    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, string> */
    private array $suppliers = [];

    public function __construct(private readonly PDO $pdo)
    {
        $this->warm();
    }

    /**
     * @param list<array<string, mixed>> $resolvedPayloads
     */
    public function materializeAll(array $resolvedPayloads): void
    {
        foreach ($resolvedPayloads as $resolved) {
            $this->ensureSimple('categories', $resolved['category'] ?? null, $this->categories);
            $this->ensureSimple('brands', $resolved['brand'] ?? null, $this->brands);
            $this->ensureSimple('dispensing_types', $resolved['dispensing_type'] ?? null, $this->dispensingTypes);
            $this->ensureSimple('species', $resolved['species'] ?? null, $this->species);
            $this->ensureSimple('specialties', $resolved['specialty'] ?? null, $this->specialties);

            foreach ($resolved['tags'] ?? [] as $tag) {
                $this->ensureSimple('product_tags', $tag, $this->tags);
            }
            foreach ($resolved['suppliers'] ?? [] as $supplierRow) {
                if (!is_array($supplierRow)) {
                    continue;
                }
                $this->ensureSimple('suppliers', $supplierRow['supplier'] ?? null, $this->suppliers);
            }
        }

        foreach ($resolvedPayloads as $resolved) {
            $category = is_array($resolved['category'] ?? null) ? $resolved['category'] : null;
            $subcategory = is_array($resolved['subcategory'] ?? null) ? $resolved['subcategory'] : null;
            if ($category !== null && $subcategory !== null) {
                $categoryId = $this->idOf($category, $this->categories);
                if ($categoryId !== null) {
                    $this->ensureChild(
                        'subcategories',
                        'category_id',
                        $categoryId,
                        (string) ($category['slug'] ?? Slug::from((string) $category['name'])),
                        $subcategory,
                        $this->subcategories
                    );
                }
            }

            $brand = is_array($resolved['brand'] ?? null) ? $resolved['brand'] : null;
            $subBrand = is_array($resolved['sub_brand'] ?? null) ? $resolved['sub_brand'] : null;
            if ($brand !== null && $subBrand !== null) {
                $brandId = $this->idOf($brand, $this->brands);
                if ($brandId !== null) {
                    $this->ensureChild(
                        'sub_brands',
                        'brand_id',
                        $brandId,
                        (string) ($brand['slug'] ?? Slug::from((string) $brand['name'])),
                        $subBrand,
                        $this->subBrands
                    );
                }
            }
        }
    }

    public function categoryId(?array $ref): ?string
    {
        return $this->idOf($ref, $this->categories);
    }

    public function subcategoryId(?array $ref, ?array $categoryRef): ?string
    {
        if ($ref === null || $categoryRef === null) {
            return null;
        }
        $parentSlug = (string) ($categoryRef['slug'] ?? '');
        $slug = (string) ($ref['slug'] ?? '');
        $key = $parentSlug . '|' . $slug;

        return $this->subcategories[$key] ?? ($ref['id'] ?? null);
    }

    public function brandId(?array $ref): ?string
    {
        return $this->idOf($ref, $this->brands);
    }

    public function subBrandId(?array $ref, ?array $brandRef): ?string
    {
        if ($ref === null || $brandRef === null) {
            return null;
        }
        $parentSlug = (string) ($brandRef['slug'] ?? '');
        $slug = (string) ($ref['slug'] ?? '');
        $key = $parentSlug . '|' . $slug;

        return $this->subBrands[$key] ?? ($ref['id'] ?? null);
    }

    public function dispensingTypeId(?array $ref): ?string
    {
        return $this->idOf($ref, $this->dispensingTypes);
    }

    public function speciesId(?array $ref): ?string
    {
        return $this->idOf($ref, $this->species);
    }

    public function specialtyId(?array $ref): ?string
    {
        return $this->idOf($ref, $this->specialties);
    }

    public function tagId(?array $ref): ?string
    {
        return $this->idOf($ref, $this->tags);
    }

    public function supplierId(?array $ref): ?string
    {
        return $this->idOf($ref, $this->suppliers);
    }

    /**
     * @param array<string, string> $cache
     */
    private function idOf(?array $ref, array $cache): ?string
    {
        if ($ref === null) {
            return null;
        }
        if (!empty($ref['id'])) {
            return (string) $ref['id'];
        }
        $slug = (string) ($ref['slug'] ?? '');
        if ($slug !== '' && isset($cache[$slug])) {
            return $cache[$slug];
        }
        if (!empty($ref['name'])) {
            $slug = Slug::from((string) $ref['name']);

            return $cache[$slug] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $ref
     * @param array<string, string> $cache
     */
    private function ensureSimple(string $table, ?array $ref, array &$cache): void
    {
        if ($ref === null || empty($ref['name'])) {
            return;
        }
        $name = (string) $ref['name'];
        $slug = (string) ($ref['slug'] ?? Slug::from($name));
        if ($slug === '' || isset($cache[$slug])) {
            return;
        }
        if (!empty($ref['id'])) {
            $cache[$slug] = (string) $ref['id'];

            return;
        }

        $allowed = ['categories', 'brands', 'dispensing_types', 'species', 'specialties', 'product_tags', 'suppliers'];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Invalid catalog table');
        }

        $find = $this->pdo->prepare("SELECT id::text AS id FROM {$table} WHERE slug = :slug LIMIT 1");
        $find->execute(['slug' => $slug]);
        $existing = $find->fetch();
        if (is_array($existing)) {
            $cache[$slug] = (string) $existing['id'];

            return;
        }

        $id = Uuid::v4()->toRfc4122();
        $ins = $this->pdo->prepare(
            "INSERT INTO {$table} (id, name, slug, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, TRUE, NOW(), NOW())
             ON CONFLICT DO NOTHING"
        );
        $ins->execute(['id' => $id, 'name' => $name, 'slug' => $slug]);

        $find->execute(['slug' => $slug]);
        $row = $find->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Failed to materialize ' . $table . ' ' . $name);
        }
        $cache[$slug] = (string) $row['id'];
    }

    /**
     * @param array<string, mixed> $ref
     * @param array<string, string> $cache
     */
    private function ensureChild(
        string $table,
        string $parentColumn,
        string $parentId,
        string $parentSlug,
        array $ref,
        array &$cache,
    ): void {
        if (empty($ref['name'])) {
            return;
        }
        $name = (string) $ref['name'];
        $slug = (string) ($ref['slug'] ?? Slug::from($name));
        $key = $parentSlug . '|' . $slug;
        if (isset($cache[$key])) {
            return;
        }
        if (!empty($ref['id'])) {
            $cache[$key] = (string) $ref['id'];

            return;
        }

        if (!in_array($table, ['subcategories', 'sub_brands'], true)) {
            throw new RuntimeException('Invalid child catalog table');
        }

        $find = $this->pdo->prepare(
            "SELECT id::text AS id FROM {$table}
             WHERE {$parentColumn}::text = :parent_id AND slug = :slug LIMIT 1"
        );
        $find->execute(['parent_id' => $parentId, 'slug' => $slug]);
        $existing = $find->fetch();
        if (is_array($existing)) {
            $cache[$key] = (string) $existing['id'];

            return;
        }

        $id = Uuid::v4()->toRfc4122();
        $ins = $this->pdo->prepare(
            "INSERT INTO {$table} (id, {$parentColumn}, name, slug, is_active, created_at, updated_at)
             VALUES (:id, :parent_id, :name, :slug, TRUE, NOW(), NOW())
             ON CONFLICT DO NOTHING"
        );
        $ins->execute([
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
        ]);

        $find->execute(['parent_id' => $parentId, 'slug' => $slug]);
        $row = $find->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Failed to materialize ' . $table . ' ' . $name);
        }
        $cache[$key] = (string) $row['id'];
    }

    private function warm(): void
    {
        foreach ($this->pdo->query('SELECT id::text AS id, slug FROM categories') ?: [] as $row) {
            $this->categories[(string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query(
            'SELECT sc.id::text AS id, sc.slug, c.slug AS parent_slug
             FROM subcategories sc INNER JOIN categories c ON c.id = sc.category_id'
        ) ?: [] as $row) {
            $this->subcategories[(string) $row['parent_slug'] . '|' . (string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, slug FROM brands') ?: [] as $row) {
            $this->brands[(string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query(
            'SELECT sb.id::text AS id, sb.slug, b.slug AS parent_slug
             FROM sub_brands sb INNER JOIN brands b ON b.id = sb.brand_id'
        ) ?: [] as $row) {
            $this->subBrands[(string) $row['parent_slug'] . '|' . (string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, slug FROM dispensing_types') ?: [] as $row) {
            $this->dispensingTypes[(string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, slug FROM species') ?: [] as $row) {
            $this->species[(string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, slug FROM specialties') ?: [] as $row) {
            $this->specialties[(string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, slug FROM product_tags') ?: [] as $row) {
            $this->tags[(string) $row['slug']] = (string) $row['id'];
        }
        foreach ($this->pdo->query('SELECT id::text AS id, slug FROM suppliers') ?: [] as $row) {
            $this->suppliers[(string) $row['slug']] = (string) $row['id'];
        }
    }
}
