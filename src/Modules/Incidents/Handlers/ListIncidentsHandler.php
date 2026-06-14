<?php

namespace App\Modules\Incidents\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Auth\RequestClinicResolver;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Incidents\Services\IncidentService;
use Throwable;

final class ListIncidentsHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly RequestClinicResolver $clinicResolver,
        private readonly IncidentService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            if ($this->access->isSuperAdmin($user)) {
                return ApiResponse::success($request, $this->service->listAll());
            }

            $clinicId = $this->clinicResolver->requireClinicId($request, $user);

            return ApiResponse::success($request, $this->service->list($clinicId));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
