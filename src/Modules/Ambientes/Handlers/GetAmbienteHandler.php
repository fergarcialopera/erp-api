<?php

namespace App\Modules\Ambientes\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Auth\RequestClinicResolver;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Ambientes\Services\AmbienteService;
use Throwable;

final class GetAmbienteHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly RequestClinicResolver $clinicResolver,
        private readonly AmbienteService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $id = (string) $request->getAttribute('ambiente_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente not found');
            }

            if ($this->access->isSuperAdmin($user) && $this->access->clinicIdFromToken($user) === '') {
                $ambiente = $this->service->getGlobal($id);
            } else {
                $clinicId = $this->clinicResolver->requireClinicId($request, $user);
                $ambiente = $this->service->getForClinic($clinicId, $id, $this->clinicResolver->isAdminView($user));
            }

            if ($ambiente === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente not found');
            }

            return ApiResponse::success($request, $ambiente);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
