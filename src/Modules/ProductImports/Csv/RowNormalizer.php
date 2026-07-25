<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Csv;

final class RowNormalizer
{
    /**
     * @param array<string, string|null> $assoc Row keyed by header name
     * @return array{
     *     odoo_id: ?string,
     *     is_active: bool,
     *     name: ?string,
     *     barcode: ?string,
     *     internal_reference: ?string,
     *     type: ?string,
     *     unit_of_measure: ?string,
     *     packaging: ?string,
     *     category: ?string,
     *     brand: ?string,
     *     dispensing_type: ?string,
     *     sub_brand: ?string,
     *     subcategory: ?string,
     *     species: ?string,
     *     specialty: ?string,
     *     national_code: ?string,
     *     tags: list<string>,
     *     suppliers: list<array{odoo_id:?string,vendor:?string,purchase_price:?float,pvp:?float,net_cost:?float}>
     * }
     */
    public function normalizeProduct(array $assoc, array $supplierRows = []): array
    {
        $tagsRaw = $this->stringOrNull($assoc[ExpectedHeaders::TAGS] ?? null);
        $tags = [];
        if ($tagsRaw !== null) {
            foreach (preg_split('/\s*,\s*/', $tagsRaw) ?: [] as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        $suppliers = [];
        $allSupplierRows = array_merge([$assoc], $supplierRows);
        foreach ($allSupplierRows as $row) {
            $vendor = $this->stringOrNull($row[ExpectedHeaders::SUPPLIER_VENDOR] ?? null);
            $odooSupplierId = $this->stringOrNull($row[ExpectedHeaders::SUPPLIER_ID] ?? null);
            $price = $this->decimalOrNull($row[ExpectedHeaders::SUPPLIER_PRICE] ?? null);
            $pvp = $this->decimalOrNull($row[ExpectedHeaders::SUPPLIER_PVP] ?? null);
            $netCost = $this->decimalOrNull($row[ExpectedHeaders::SUPPLIER_NET_COST] ?? null);
            if ($vendor === null && $odooSupplierId === null && $price === null && $pvp === null && $netCost === null) {
                continue;
            }
            $suppliers[] = [
                'odoo_id' => $odooSupplierId,
                'vendor' => $vendor,
                'purchase_price' => $price,
                'pvp' => $pvp,
                'net_cost' => $netCost,
            ];
        }

        return [
            'odoo_id' => $this->stringOrNull($assoc[ExpectedHeaders::ID] ?? null),
            'is_active' => $this->boolOrDefault($assoc[ExpectedHeaders::ACTIVE] ?? null, true),
            'name' => $this->stringOrNull($assoc[ExpectedHeaders::NAME] ?? null),
            'barcode' => $this->barcodeOrNull($assoc[ExpectedHeaders::BARCODE] ?? null),
            'internal_reference' => $this->stringOrNull($assoc[ExpectedHeaders::INTERNAL_REFERENCE] ?? null),
            'type' => $this->stringOrNull($assoc[ExpectedHeaders::TYPE] ?? null),
            'unit_of_measure' => $this->stringOrNull($assoc[ExpectedHeaders::UNIT_OF_MEASURE] ?? null) ?? 'Unidades',
            'packaging' => $this->stringOrNull($assoc[ExpectedHeaders::PACKAGING] ?? null),
            'category' => $this->stringOrNull($assoc[ExpectedHeaders::CATEGORY] ?? null),
            'brand' => $this->stringOrNull($assoc[ExpectedHeaders::BRAND] ?? null),
            'dispensing_type' => $this->stringOrNull($assoc[ExpectedHeaders::DISPENSING_TYPE] ?? null),
            'sub_brand' => $this->stringOrNull($assoc[ExpectedHeaders::SUB_BRAND] ?? null),
            'subcategory' => $this->stringOrNull($assoc[ExpectedHeaders::SUBCATEGORY] ?? null),
            'species' => $this->stringOrNull($assoc[ExpectedHeaders::SPECIES] ?? null),
            'specialty' => $this->stringOrNull($assoc[ExpectedHeaders::SPECIALTY] ?? null),
            'national_code' => $this->stringOrNull($assoc[ExpectedHeaders::NATIONAL_CODE] ?? null),
            'tags' => array_keys($tags),
            'suppliers' => $suppliers,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function barcodeOrNull(mixed $value): ?string
    {
        $raw = $this->stringOrNull($value);
        if ($raw === null || $raw === '0') {
            return null;
        }

        return $raw;
    }

    private function boolOrDefault(mixed $value, bool $default): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        // Formato español ("10,49" / "1.234,56") o punto decimal ("10.49").
        $normalized = str_replace([' ', "\xc2\xa0"], '', $raw);
        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }
}
