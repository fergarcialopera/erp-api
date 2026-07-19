<?php

declare(strict_types=1);

namespace App\Modules\Subcategories\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Subcategories\Services\SubcategoryService;
use Throwable;

final class ListSubcategoriesHandler
{
    public function __construct(private readonly SubcategoryService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $qp = $request->getQueryParams();
            $active = null;
            if (array_key_exists('active', $qp)) {
                $bool = filter_var($qp['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid active filter');
                }
                $active = (bool) $bool;
            }

            $categoryId = null;
            if (array_key_exists('category_id', $qp)) {
                $categoryId = trim((string) $qp['category_id']);
                if ($categoryId === '') {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid category_id');
                }
            }

            return ApiResponse::success($request, $this->service->list($active, $categoryId));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
