<?php

declare(strict_types=1);

namespace App\Modules\Categories\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Categories\Services\CategoryService;
use Throwable;

final class GetCategoryHandler
{
    public function __construct(private readonly CategoryService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('category_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Category not found');
            }

            $category = $this->service->get($id);
            if ($category === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Category not found');
            }

            return ApiResponse::success($request, $category);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
