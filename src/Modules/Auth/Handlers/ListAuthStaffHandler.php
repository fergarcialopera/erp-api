<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\AuthService;
use Throwable;

final class ListAuthStaffHandler
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $clinic = (array) $request->getAttribute('clinic', []);
            $clinicId = (string) ($clinic['clinic_id'] ?? '');
            if ($clinicId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic session');
            }

            return ApiResponse::success($request, $this->service->listStaff($clinicId));
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
