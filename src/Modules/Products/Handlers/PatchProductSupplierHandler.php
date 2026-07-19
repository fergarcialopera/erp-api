<?php

declare(strict_types=1);

namespace App\Modules\Products\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Validators\ProductValidator;
use Throwable;

final class PatchProductSupplierHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ProductValidator $validator,
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

            $dto = $this->validator->validatePatchSupplier($request->getParsedBody());
            $link = $this->service->updateSupplier($productId, $productSupplierId, $dto);
            if ($link === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product supplier not found');
            }

            return ApiResponse::success($request, $link);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
