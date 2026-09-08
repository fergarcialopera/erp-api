<?php

declare(strict_types=1);

namespace App\Modules\Products\Support;

use InvalidArgumentException;

final class ProductListQuery
{
    /**
     * @param array<string, mixed> $qp
     */
    public static function parseActive(array $qp): ?bool
    {
        if (!array_key_exists('active', $qp)) {
            return null;
        }
        $bool = filter_var($qp['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new InvalidArgumentException('Invalid active filter');
        }

        return (bool) $bool;
    }

    /**
     * @param array<string, mixed> $qp
     * @return array{category_id:?string,subcategory_id:?string,brand_id:?string,dispensing_type_id:?string,supplier_id:?string,search:?string}
     */
    public static function parseFilters(array $qp): array
    {
        $uuidKeys = ['category_id', 'subcategory_id', 'brand_id', 'dispensing_type_id', 'supplier_id'];
        $filters = [
            'category_id' => null,
            'subcategory_id' => null,
            'brand_id' => null,
            'dispensing_type_id' => null,
            'supplier_id' => null,
            'search' => null,
        ];

        foreach ($uuidKeys as $key) {
            if (!array_key_exists($key, $qp)) {
                continue;
            }
            $value = trim((string) $qp[$key]);
            if ($value === '') {
                throw new InvalidArgumentException("Invalid {$key}");
            }
            $filters[$key] = $value;
        }

        if (array_key_exists('search', $qp)) {
            $search = trim((string) $qp['search']);
            if ($search !== '') {
                if (mb_strlen($search) > 100) {
                    throw new InvalidArgumentException('Invalid search filter');
                }
                $filters['search'] = $search;
            }
        }

        return $filters;
    }
}
