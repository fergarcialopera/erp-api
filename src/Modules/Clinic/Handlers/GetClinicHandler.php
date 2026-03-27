<?php

namespace App\Modules\Clinic\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;

final class GetClinicHandler
{
    public function __construct(private readonly ClinicService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        $user = (array) $request->getAttribute('user', []);
        $clinicId = (string) ($user['clinic_id'] ?? '');
        if ($clinicId === '') {
            return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic_id in user context');
        }

        $clinic = $this->service->getById($clinicId);
        if ($clinic === null) {
            return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
        }

        return ApiResponse::success($request, $clinic);
    }
}

