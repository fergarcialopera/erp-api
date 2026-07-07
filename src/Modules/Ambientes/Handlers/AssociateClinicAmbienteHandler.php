<?php

declare(strict_types=1);

namespace App\Modules\Ambientes\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Ambientes\Services\AmbienteService;
use InvalidArgumentException;
use Throwable;

final class AssociateClinicAmbienteHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly AmbienteService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $clinicId = (string) $request->getAttribute('clinic_id', '');
            $body = $request->getParsedBody();
            $ambienteId = trim((string) ($body['ambiente_id'] ?? ''));
            if ($clinicId === '' || $ambienteId === '') {
                throw new InvalidArgumentException('ambiente_id is required');
            }

            $ambiente = $this->service->associateToClinic($clinicId, $ambienteId, AuditActor::fromUser($user));
            if ($ambiente === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente not found');
            }

            return ApiResponse::success($request, $ambiente, status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
