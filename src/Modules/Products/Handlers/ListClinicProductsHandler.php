<?php

declare(strict_types=1);

namespace App\Modules\Products\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Support\ProductListQuery;
use InvalidArgumentException;
use Throwable;

final class ListClinicProductsHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ClinicService $clinics,
        private readonly ProductService $service
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

            $qp = $request->getQueryParams();
            $active = ProductListQuery::parseActive($qp);
            $filters = ProductListQuery::parseFilters($qp);

            return ApiResponse::success(
                $request,
                $this->service->listCatalogForClinic($clinicId, $active, $filters)
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
