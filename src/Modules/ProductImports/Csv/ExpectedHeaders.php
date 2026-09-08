<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Csv;

final class ExpectedHeaders
{
    public const ID = 'ID';
    public const ACTIVE = 'Activo';
    public const NAME = 'Nombre';
    public const BARCODE = 'Código de barras';
    public const INTERNAL_REFERENCE = 'Referencia interna';
    public const TYPE = 'Tipo';
    public const UNIT_OF_MEASURE = 'Unidad de medida';
    public const PACKAGING = 'Empaquetado';
    public const CATEGORY = 'Categoría de producto';
    public const BRAND = 'Marca';
    public const DISPENSING_TYPE = 'Tipo de dispensación';
    public const SUB_BRAND = 'Submarca';
    public const SUBCATEGORY = 'Subcategoría';
    public const SPECIES = 'Especie';
    public const SPECIALTY = 'Especialidad';
    public const NATIONAL_CODE = 'Código Nacional';
    public const TAGS = 'Etiquetas de la plantilla de producto';
    public const SUPPLIER_ID = 'Proveedores/ID';
    public const SUPPLIER_VENDOR = 'Proveedores/Vendor';
    public const SUPPLIER_PRICE = 'Proveedores/Precio';
    public const SUPPLIER_PVP = 'Proveedores/PVP';
    public const SUPPLIER_NET_COST = 'Proveedores/Coste Neto';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ID,
            self::ACTIVE,
            self::NAME,
            self::BARCODE,
            self::INTERNAL_REFERENCE,
            self::TYPE,
            self::UNIT_OF_MEASURE,
            self::PACKAGING,
            self::CATEGORY,
            self::BRAND,
            self::DISPENSING_TYPE,
            self::SUB_BRAND,
            self::SUBCATEGORY,
            self::SPECIES,
            self::SPECIALTY,
            self::NATIONAL_CODE,
            self::TAGS,
            self::SUPPLIER_ID,
            self::SUPPLIER_VENDOR,
            self::SUPPLIER_PRICE,
            self::SUPPLIER_PVP,
            self::SUPPLIER_NET_COST,
        ];
    }
}
