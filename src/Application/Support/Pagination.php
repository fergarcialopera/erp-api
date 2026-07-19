<?php

declare(strict_types=1);

namespace App\Application\Support;

final class Pagination
{
    /**
     * @param array<string, mixed> $queryParams
     * @return array{page: int, per_page: int, offset: int}
     */
    public static function resolve(array $queryParams, int $defaultPerPage = 50, int $maxPerPage = 100): array
    {
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $perPage = (int) ($queryParams['per_page'] ?? $defaultPerPage);
        $perPage = max(1, min($maxPerPage, $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
        ];
    }
}
