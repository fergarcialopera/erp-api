<?php

declare(strict_types=1);

namespace App\Modules\Products\Validators;

use App\Modules\Products\DTOs\CreateProductDTO;
use App\Modules\Products\DTOs\PatchProductDTO;
use App\Modules\Products\DTOs\PatchProductSupplierDTO;
use App\Modules\Products\DTOs\UpsertProductSupplierDTO;
use InvalidArgumentException;

final class ProductValidator
{
    public function validateCreate(array $payload): CreateProductDTO
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }

        $isActive = $this->parseBool($payload, 'is_active', true);
        $barcode = $this->optionalString($payload, 'barcode');
        $internalReference = $this->optionalString($payload, 'internal_reference');
        $categoryId = $this->optionalUuid($payload, 'category_id');
        $subcategoryId = $this->optionalUuid($payload, 'subcategory_id');
        $brandId = $this->optionalUuid($payload, 'brand_id');
        $dispensingTypeId = $this->optionalUuid($payload, 'dispensing_type_id');
        $unitOfMeasure = array_key_exists('unit_of_measure', $payload)
            ? trim((string) $payload['unit_of_measure'])
            : 'Unidades';
        if ($unitOfMeasure === '') {
            throw new InvalidArgumentException('Invalid unit_of_measure');
        }

        $nationalCode = $this->optionalString($payload, 'national_code');
        $packaging = $this->optionalString($payload, 'packaging');
        $subBrandId = $this->optionalUuid($payload, 'sub_brand_id');
        $speciesId = $this->optionalUuid($payload, 'species_id');
        $specialtyId = $this->optionalUuid($payload, 'specialty_id');
        $tagIds = array_key_exists('tag_ids', $payload) ? $this->parseTagIds($payload['tag_ids']) : null;

        $this->assertSubcategoryBelongsToCategory($categoryId, $subcategoryId, $payload);

        return new CreateProductDTO(
            $name,
            $isActive,
            $barcode,
            $internalReference,
            $categoryId,
            $subcategoryId,
            $brandId,
            $dispensingTypeId,
            $unitOfMeasure,
            $nationalCode,
            $packaging,
            $subBrandId,
            $speciesId,
            $specialtyId,
            $tagIds,
        );
    }

    public function validatePatch(array $payload): PatchProductDTO
    {
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Invalid name');
        }

        $isActive = null;
        if (array_key_exists('is_active', $payload)) {
            $isActive = $this->parseBool($payload, 'is_active', null);
            if ($isActive === null) {
                throw new InvalidArgumentException('Invalid is_active');
            }
        }

        $barcodeTouched = array_key_exists('barcode', $payload);
        $barcode = $barcodeTouched ? $this->optionalString($payload, 'barcode') : null;
        $internalReferenceTouched = array_key_exists('internal_reference', $payload);
        $internalReference = $internalReferenceTouched ? $this->optionalString($payload, 'internal_reference') : null;
        $categoryIdTouched = array_key_exists('category_id', $payload);
        $categoryId = $categoryIdTouched ? $this->optionalUuid($payload, 'category_id') : null;
        $subcategoryIdTouched = array_key_exists('subcategory_id', $payload);
        $subcategoryId = $subcategoryIdTouched ? $this->optionalUuid($payload, 'subcategory_id') : null;
        $brandIdTouched = array_key_exists('brand_id', $payload);
        $brandId = $brandIdTouched ? $this->optionalUuid($payload, 'brand_id') : null;
        $dispensingTypeIdTouched = array_key_exists('dispensing_type_id', $payload);
        $dispensingTypeId = $dispensingTypeIdTouched ? $this->optionalUuid($payload, 'dispensing_type_id') : null;
        $unitOfMeasure = array_key_exists('unit_of_measure', $payload)
            ? trim((string) $payload['unit_of_measure'])
            : null;
        if ($unitOfMeasure !== null && $unitOfMeasure === '') {
            throw new InvalidArgumentException('Invalid unit_of_measure');
        }

        $nationalCodeTouched = array_key_exists('national_code', $payload);
        $nationalCode = $nationalCodeTouched ? $this->optionalString($payload, 'national_code') : null;
        $packagingTouched = array_key_exists('packaging', $payload);
        $packaging = $packagingTouched ? $this->optionalString($payload, 'packaging') : null;
        $subBrandIdTouched = array_key_exists('sub_brand_id', $payload);
        $subBrandId = $subBrandIdTouched ? $this->optionalUuid($payload, 'sub_brand_id') : null;
        $speciesIdTouched = array_key_exists('species_id', $payload);
        $speciesId = $speciesIdTouched ? $this->optionalUuid($payload, 'species_id') : null;
        $specialtyIdTouched = array_key_exists('specialty_id', $payload);
        $specialtyId = $specialtyIdTouched ? $this->optionalUuid($payload, 'specialty_id') : null;
        $tagIdsTouched = array_key_exists('tag_ids', $payload);
        $tagIds = $tagIdsTouched ? ($this->parseTagIds($payload['tag_ids']) ?? []) : null;

        if (
            $name === null
            && $isActive === null
            && !$barcodeTouched
            && !$internalReferenceTouched
            && !$categoryIdTouched
            && !$subcategoryIdTouched
            && !$brandIdTouched
            && !$dispensingTypeIdTouched
            && $unitOfMeasure === null
            && !$nationalCodeTouched
            && !$packagingTouched
            && !$subBrandIdTouched
            && !$speciesIdTouched
            && !$specialtyIdTouched
            && !$tagIdsTouched
        ) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchProductDTO(
            $name,
            $isActive,
            $barcodeTouched,
            $barcode,
            $internalReferenceTouched,
            $internalReference,
            $categoryIdTouched,
            $categoryId,
            $subcategoryIdTouched,
            $subcategoryId,
            $brandIdTouched,
            $brandId,
            $dispensingTypeIdTouched,
            $dispensingTypeId,
            $unitOfMeasure,
            $nationalCodeTouched,
            $nationalCode,
            $packagingTouched,
            $packaging,
            $subBrandIdTouched,
            $subBrandId,
            $speciesIdTouched,
            $speciesId,
            $specialtyIdTouched,
            $specialtyId,
            $tagIdsTouched,
            $tagIds,
        );
    }

    public function validateCreateSupplier(array $payload): UpsertProductSupplierDTO
    {
        $supplierId = trim((string) ($payload['supplier_id'] ?? ''));
        if ($supplierId === '') {
            throw new InvalidArgumentException('Invalid supplier_id');
        }

        return new UpsertProductSupplierDTO(
            $supplierId,
            $this->optionalString($payload, 'supplier_reference'),
            $this->optionalDecimal($payload, 'purchase_price'),
            $this->optionalDecimal($payload, 'pvp'),
            $this->optionalDecimal($payload, 'net_cost'),
            $this->parseBool($payload, 'is_preferred', false),
        );
    }

    public function validatePatchSupplier(array $payload): PatchProductSupplierDTO
    {
        $supplierIdTouched = array_key_exists('supplier_id', $payload);
        $supplierId = $supplierIdTouched ? trim((string) $payload['supplier_id']) : null;
        if ($supplierIdTouched && ($supplierId === null || $supplierId === '')) {
            throw new InvalidArgumentException('Invalid supplier_id');
        }

        $supplierReferenceTouched = array_key_exists('supplier_reference', $payload);
        $purchasePriceTouched = array_key_exists('purchase_price', $payload);
        $pvpTouched = array_key_exists('pvp', $payload);
        $netCostTouched = array_key_exists('net_cost', $payload);

        $isPreferred = null;
        if (array_key_exists('is_preferred', $payload)) {
            $isPreferred = $this->parseBool($payload, 'is_preferred', null);
            if ($isPreferred === null) {
                throw new InvalidArgumentException('Invalid is_preferred');
            }
        }

        if (
            !$supplierIdTouched
            && !$supplierReferenceTouched
            && !$purchasePriceTouched
            && !$pvpTouched
            && !$netCostTouched
            && $isPreferred === null
        ) {
            throw new InvalidArgumentException('No fields to update');
        }

        return new PatchProductSupplierDTO(
            $supplierIdTouched,
            $supplierId,
            $supplierReferenceTouched,
            $supplierReferenceTouched ? $this->optionalString($payload, 'supplier_reference') : null,
            $purchasePriceTouched,
            $purchasePriceTouched ? $this->optionalDecimal($payload, 'purchase_price') : null,
            $pvpTouched,
            $pvpTouched ? $this->optionalDecimal($payload, 'pvp') : null,
            $netCostTouched,
            $netCostTouched ? $this->optionalDecimal($payload, 'net_cost') : null,
            $isPreferred,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSubcategoryBelongsToCategory(?string $categoryId, ?string $subcategoryId, array $payload): void
    {
        // Validación relacional completa se hace en el servicio (necesita DB).
        // Aquí solo comprobamos coherencia de presencia.
        if ($subcategoryId !== null && $categoryId === null && !array_key_exists('category_id', $payload)) {
            // Permitido en create si solo subcategory; el servicio verifica pertenencia vía FK de subcategory.
            return;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseBool(array $payload, string $key, ?bool $default): ?bool
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }
        $raw = $payload[$key];
        if (is_bool($raw)) {
            return $raw;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
            return null;
        }
        $value = trim((string) $payload[$key]);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalUuid(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
            return null;
        }
        $value = trim((string) $payload[$key]);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>|null Lista de UUIDs deduplicada; null si el valor es null.
     */
    private function parseTagIds(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Invalid tag_ids');
        }

        $ids = [];
        foreach ($raw as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Invalid tag_ids');
            }
            $ids[trim($value)] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalDecimal(array $payload, string $key): ?float
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
            return null;
        }
        if (!is_numeric($payload[$key])) {
            throw new InvalidArgumentException('Invalid ' . $key);
        }

        return (float) $payload[$key];
    }
}
