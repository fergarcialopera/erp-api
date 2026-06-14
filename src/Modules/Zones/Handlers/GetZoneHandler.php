<?php

namespace App\Modules\Zones\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Auth\RequestClinicResolver;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Zones\Services\ZoneService;
use Throwable;

final class GetZoneHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly RequestClinicResolver $clinicResolver,
        private readonly ZoneService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $id = (string) $request->getAttribute('zone_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Zone not found');
            }

            if ($this->access->isSuperAdmin($user) && $this->access->clinicIdFromToken($user) === '') {
                $zone = $this->service->getGlobal($id);
            } else {
                $clinicId = $this->clinicResolver->requireClinicId($request, $user);
                $zone = $this->service->getForClinic($clinicId, $id);
            }

            if ($zone === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Zone not found');
            }

            return ApiResponse::success($request, $zone);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
