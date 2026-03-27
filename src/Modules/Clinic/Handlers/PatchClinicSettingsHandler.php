<?php

namespace App\Modules\Clinic\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Validators\ClinicSettingsValidator;
use App\Modules\Settings\DTOs\UpsertSettingDTO;
use App\Modules\Settings\Services\SettingService;
use Throwable;

final class PatchClinicSettingsHandler
{
    public function __construct(
        private readonly ClinicSettingsValidator $validator,
        private readonly SettingService $settings
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

            $dto = $this->validator->validatePatch($request->getParsedBody());

            $result = [];
            if ($dto->openLatencyMs !== null) {
                $result[] = $this->settings->upsert($clinicId, new UpsertSettingDTO('clinic.open_latency_ms', (string) $dto->openLatencyMs));
            }

            return ApiResponse::success($request, $result, status: 200);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}

