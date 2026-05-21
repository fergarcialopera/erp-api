<?php

namespace App\Modules\ExitLogs\Handlers;

use App\Application\ExitLogs\ExitLogUserScope;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\ExitLogs\Services\ExitLogService;
use Throwable;

final class ListExitLogsHandler
{
    public function __construct(private readonly ExitLogService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) ($user['clinic_id'] ?? '');
            if ($clinicId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic_id in user context');
            }

            $scope = ExitLogUserScope::restrictToCreatorForStaff($user);

            return ApiResponse::success($request, $this->service->list($clinicId, $scope));
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
