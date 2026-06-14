<?php

namespace App\Modules\Zones\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Zones\Services\ZoneService;
use Throwable;

final class GetZoneHandler
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

            $row = $this->service->get($clinicId, $id);
            if ($row === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Zone not found');
            }
            return ApiResponse::success($request, $row);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}

