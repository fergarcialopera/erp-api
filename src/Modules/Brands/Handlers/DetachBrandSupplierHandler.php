<?php

declare(strict_types=1);

namespace App\Modules\Brands\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Brands\Services\BrandService;
use Throwable;

final class DetachBrandSupplierHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly BrandService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $brandId = (string) $request->getAttribute('brand_id', '');
            $supplierId = (string) $request->getAttribute('supplier_id', '');
            if ($brandId === '' || $supplierId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Brand supplier link not found');
            }

            if (!$this->service->detachSupplier($brandId, $supplierId)) {
                return ApiResponse::error($request, 404, 'Not Found', 'Brand supplier link not found');
            }

            return ApiResponse::success($request, ['deleted' => true]);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
