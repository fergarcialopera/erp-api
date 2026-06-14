<?php

declare(strict_types=1);

namespace App\Modules\Ambientes\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Ambientes\Services\AmbienteService;
use Throwable;

final class DisassociateClinicAmbienteHandler
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
            $ambienteId = (string) $request->getAttribute('ambiente_id', '');
            if ($clinicId === '' || $ambienteId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente association not found');
            }

            if (!$this->service->disassociateFromClinic($clinicId, $ambienteId)) {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente association not found');
            }

            return ApiResponse::success($request, ['deleted' => true]);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
