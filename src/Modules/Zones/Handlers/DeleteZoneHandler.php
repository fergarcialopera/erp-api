<?php

namespace App\Modules\Zones\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Zones\Services\ZoneService;
use Throwable;

final class DeleteZoneHandler
{
    public function __construct(private readonly ZoneService $service)
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
            $id = (string) $request->getAttribute('zone_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Zone not found');
            }
            $deleted = $this->service->softDelete($clinicId, $id);
            if (!$deleted) {
                return ApiResponse::error($request, 404, 'Not Found', 'Zone not found');
            }
            return ApiResponse::success($request, ['deleted' => true]);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}

