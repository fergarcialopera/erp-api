<?php

namespace App\Modules\Products\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Auth\RequestClinicResolver;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Products\Services\ProductService;
use Throwable;

final class ListProductsHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly RequestClinicResolver $clinicResolver,
        private readonly ProductService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $active = null;
            $qp = $request->getQueryParams();
            if (array_key_exists('active', $qp)) {
                $bool = filter_var($qp['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid active filter');
                }
                $active = (bool) $bool;
            }

            if ($this->access->isSuperAdmin($user) && $this->access->clinicIdFromToken($user) === '') {
                return ApiResponse::success($request, $this->service->listGlobal($active));
            }

            $clinicId = $this->clinicResolver->requireClinicId($request, $user);
            $adminView = $this->clinicResolver->isAdminView($user);

            return ApiResponse::success($request, $this->service->listForClinic($clinicId, $active, $adminView));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
