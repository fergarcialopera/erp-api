<?php

declare(strict_types=1);

namespace App\Modules\Subcategories\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Subcategories\Services\SubcategoryService;
use Throwable;

final class GetSubcategoryHandler
{
    public function __construct(private readonly SubcategoryService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('subcategory_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Subcategory not found');
            }

            $subcategory = $this->service->get($id);
            if ($subcategory === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Subcategory not found');
            }

            return ApiResponse::success($request, $subcategory);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
