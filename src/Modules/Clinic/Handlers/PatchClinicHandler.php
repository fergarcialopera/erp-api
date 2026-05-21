<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Clinic\Validators\ClinicValidator;
use Throwable;

final class PatchClinicHandler
{
    public function __construct(
        private readonly ClinicValidator $validator,
        private readonly ClinicService $service
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
            $updated = $this->service->patch($clinicId, $dto->visible, $dto->password, $dto->name);
            if ($updated === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            return ApiResponse::success($request, $updated);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
