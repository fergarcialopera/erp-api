<?php

declare(strict_types=1);

namespace App\Modules\SubBrands\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\SubBrands\Services\SubBrandService;
use Throwable;

final class ListSubBrandsHandler
{
    public function __construct(private readonly SubBrandService $service)
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

            $brandId = null;
            if (array_key_exists('brand_id', $qp)) {
                $brandId = trim((string) $qp['brand_id']);
                if ($brandId === '') {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid brand_id');
                }
            }

            return ApiResponse::success($request, $this->service->list($active, $brandId));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
