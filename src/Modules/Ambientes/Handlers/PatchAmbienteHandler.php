<?php

namespace App\Modules\Ambientes\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Ambientes\Services\AmbienteService;
use App\Modules\Ambientes\Validators\AmbienteValidator;
use Throwable;

final class PatchAmbienteHandler
{
    public function __construct(
        private readonly AmbienteValidator $validator,
        private readonly AmbienteService $service
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
            $id = (string) $request->getAttribute('ambiente_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente not found');
            }
            $dto = $this->validator->validatePatch($request->getParsedBody());
            $updated = $this->service->patch($clinicId, $id, $dto);
            if ($updated === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente not found');
            }
            return ApiResponse::success($request, $updated);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}

