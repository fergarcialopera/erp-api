<?php

declare(strict_types=1);

namespace App\Modules\Brands\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Brands\Services\BrandService;
use Throwable;

final class GetBrandHandler
{
    public function __construct(private readonly BrandService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('brand_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Brand not found');
            }

            $brand = $this->service->get($id);
            if ($brand === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Brand not found');
            }

            return ApiResponse::success($request, $brand);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
