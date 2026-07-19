<?php

declare(strict_types=1);

namespace App\Modules\Products\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Products\Services\ProductService;
use Throwable;

final class DeleteProductSupplierHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ProductService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $productId = (string) $request->getAttribute('product_id', '');
            $productSupplierId = (string) $request->getAttribute('product_supplier_id', '');
            if ($productId === '' || $productSupplierId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product supplier not found');
            }

            if (!$this->service->deleteSupplier($productId, $productSupplierId)) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product supplier not found');
            }

            return ApiResponse::success($request, ['deleted' => true]);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
