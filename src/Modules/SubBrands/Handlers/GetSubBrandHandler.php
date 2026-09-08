<?php

declare(strict_types=1);

namespace App\Modules\SubBrands\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\SubBrands\Services\SubBrandService;
use Throwable;

final class GetSubBrandHandler
{
    public function __construct(private readonly SubBrandService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('sub_brand_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Sub-brand not found');
            }

            $subBrand = $this->service->get($id);
            if ($subBrand === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Sub-brand not found');
            }

            return ApiResponse::success($request, $subBrand);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
