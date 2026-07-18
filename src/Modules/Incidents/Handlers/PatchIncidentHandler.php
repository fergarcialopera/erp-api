<?php

declare(strict_types=1);

namespace App\Modules\Incidents\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Auth\RequestClinicResolver;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Incidents\Services\IncidentService;
use App\Modules\Incidents\Validators\IncidentValidator;
use Throwable;

final class PatchIncidentHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly IncidentValidator $validator,
        private readonly IncidentService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $incidentId = (string) $request->getAttribute('incident_id', '');
            if ($incidentId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Incident not found');
            }

            $dto = $this->validator->validatePatch($request->getParsedBody());
            $incident = $this->service->patch($incidentId, $dto, AuditActor::fromUser($user));
            if ($incident === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Incident not found');
            }

            return ApiResponse::success($request, $incident);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
