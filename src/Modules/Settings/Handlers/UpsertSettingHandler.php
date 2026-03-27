<?php

namespace App\Modules\Settings\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Settings\Services\SettingService;
use App\Modules\Settings\Validators\SettingValidator;
use Throwable;

final class UpsertSettingHandler
{
    public function __construct(
        private readonly SettingValidator $validator,
        private readonly SettingService $service
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
            return ApiResponse::success($request, $this->service->upsert($clinicId, $dto), status: 201);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
