<?php

namespace App\Modules\Inventory\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Validators\InventoryValidator;
use Throwable;

final class PatchInventoryProductHandler
{
    public function __construct(
        private readonly InventoryValidator $validator,
        private readonly InventoryService $service
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

            $productId = (string) $request->getAttribute('product_id', '');
            if ($productId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            $locations = $this->validator->validateAdjustQuantities($request->getParsedBody());
            $data = $this->service->adjustProductQuantities($clinicId, $productId, $locations, AuditActor::fromUser($user));
            if ($data === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            return ApiResponse::success($request, $data);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
