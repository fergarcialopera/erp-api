<?php

namespace App\Modules\Compartments\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Compartments\Services\CompartmentService;
use Throwable;

final class ListCompartmentsHandler
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

            $qp = $request->getQueryParams();
            $lockerId = array_key_exists('locker_id', $qp) ? trim((string) $qp['locker_id']) : null;
            if ($lockerId === '') {
                $lockerId = null;
            }

            $active = null;
            if (array_key_exists('active', $qp)) {
                $bool = filter_var($qp['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid active filter');
                }
                $active = (bool) $bool;
            }

            return ApiResponse::success($request, $this->service->list($clinicId, $lockerId, $active));
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}

