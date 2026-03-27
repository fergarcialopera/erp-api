<?php

namespace App\Modules\ExitLogs\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\ExitLogs\Services\ExitLogService;
use App\Modules\ExitLogs\Validators\ExitLogValidator;
use Throwable;

final class CreateExitLogHandler
{
    public function __construct(
        private readonly ExitLogValidator $validator,
        private readonly ExitLogService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) ($user['clinic_id'] ?? '');
            $userId = (string) ($user['user_id'] ?? '');
            if ($clinicId === '' || $userId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Invalid user context');
            }

            $dto = $this->validator->validateCreate($request->getParsedBody());
            return ApiResponse::success($request, $this->service->create($clinicId, $userId, $dto), status: 201);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
