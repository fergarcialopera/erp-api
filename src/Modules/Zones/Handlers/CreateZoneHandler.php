<?php

namespace App\Modules\Zones\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Zones\Services\ZoneService;
use App\Modules\Zones\Validators\ZoneValidator;
use Throwable;

final class CreateZoneHandler
{
    public function __construct(
        private readonly ZoneValidator $validator,
        private readonly ZoneService $service
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
            $dto = $this->validator->validateCreate($request->getParsedBody());
            return ApiResponse::success($request, $this->service->create($clinicId, $dto), status: 201);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}

