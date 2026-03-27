<?php

namespace App\Modules\Compartments\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Compartments\Services\CompartmentService;
use Throwable;

final class GetCompartmentHandler
{
    public function __construct(private readonly CompartmentService $service)
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
            $id = (string) $request->getAttribute('compartment_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Compartment not found');
            }

            $row = $this->service->get($clinicId, $id);
            if ($row === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Compartment not found');
            }
            return ApiResponse::success($request, $row);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}

