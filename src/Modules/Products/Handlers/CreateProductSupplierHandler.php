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

final class CreateProductSupplierHandler
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
            if ($productId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            $dto = $this->validator->validateCreateSupplier($request->getParsedBody());

            return ApiResponse::success($request, $this->service->addSupplier($productId, $dto), status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
