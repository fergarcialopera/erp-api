<?php

namespace App\Modules\Products\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Validators\ProductValidator;
use Throwable;

final class PatchProductHandler
{
    public function __construct(
        private readonly ProductValidator $validator,
        private readonly ProductService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) ($user['clinic_id'] ?? '');
            if ($clinicId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic_id in user context');
            }

            $id = (string) $request->getAttribute('product_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            $dto = $this->validator->validatePatch($request->getParsedBody());
            $product = $this->service->patch($clinicId, $id, $dto);
            if ($product === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            return ApiResponse::success($request, $product);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}

