<?php

namespace App\Modules\Inventory\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Validators\InventoryValidator;
use Throwable;

final class UpsertInventoryHandler
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

            $dto = $this->validator->validateUpsert($request->getParsedBody());
            $item = $this->service->upsertByClinic($clinicId, $dto);

            return ApiResponse::success($request, $item, status: 201);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
