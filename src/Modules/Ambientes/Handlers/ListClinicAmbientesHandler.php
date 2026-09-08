<?php

declare(strict_types=1);

namespace App\Modules\Ambientes\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Ambientes\Services\AmbienteService;
use App\Modules\Clinic\Services\ClinicService;
use InvalidArgumentException;
use Throwable;

final class ListClinicAmbientesHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ClinicService $clinics,
        private readonly AmbienteService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) $request->getAttribute('clinic_id', '');
            if ($clinicId === '' || $this->clinics->getById($clinicId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $this->access->assertAdminOfClinic($user, $clinicId);

            $active = null;
            $qp = $request->getQueryParams();
            if (array_key_exists('active', $qp)) {
                $bool = filter_var($qp['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    throw new InvalidArgumentException('Invalid active filter');
                }
                $active = (bool) $bool;
            }

            return ApiResponse::success(
                $request,
                $this->service->listForClinic($clinicId, $active, true)
            );
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
