<?php

namespace App\Modules\Lockers\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Lockers\Services\LockerService;
use Throwable;

final class GetLockerHandler
{
    public function __construct(private readonly LockerService $service)
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
            $id = (string) $request->getAttribute('locker_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Locker not found');
            }
            $locker = $this->service->get($clinicId, $id);
            if ($locker === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Locker not found');
            }
            return ApiResponse::success($request, $locker);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}

