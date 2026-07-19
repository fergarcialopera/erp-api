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
            $qp = $request->getQueryParams();

            $active = null;
            if (array_key_exists('active', $qp)) {
                $bool = filter_var($qp['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid active filter');
                }
                $active = (bool) $bool;
            }

            $filters = $this->parseListFilters($request, $qp);
            if ($filters instanceof Response) {
                return $filters;
            }

            if ($this->access->isSuperAdmin($user) && $this->access->clinicIdFromToken($user) === '') {
                return ApiResponse::success($request, $this->service->listGlobal($active, $filters));
            }

            $clinicId = $this->clinicResolver->requireClinicId($request, $user);
            $adminView = $this->clinicResolver->isAdminView($user);

            return ApiResponse::success(
                $request,
                $this->service->listForClinic($clinicId, $active, $adminView, $filters)
            );
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $qp
     * @return array{category_id:?string,subcategory_id:?string,brand_id:?string,dispensing_type_id:?string,supplier_id:?string,search:?string}|Response
     */
    private function parseListFilters(Request $request, array $qp): array|Response
    {
        $uuidKeys = ['category_id', 'subcategory_id', 'brand_id', 'dispensing_type_id', 'supplier_id'];
        $filters = [
            'category_id' => null,
            'subcategory_id' => null,
            'brand_id' => null,
            'dispensing_type_id' => null,
            'supplier_id' => null,
            'search' => null,
        ];

        foreach ($uuidKeys as $key) {
            if (!array_key_exists($key, $qp)) {
                continue;
            }
            $value = trim((string) $qp[$key]);
            if ($value === '') {
                return ApiResponse::error($request, 422, 'Unprocessable Entity', "Invalid {$key}");
            }
            $filters[$key] = $value;
        }

        if (array_key_exists('search', $qp)) {
            $search = trim((string) $qp['search']);
            if ($search !== '') {
                if (mb_strlen($search) > 100) {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid search filter');
                }
                $filters['search'] = $search;
            }
        }

        return $filters;
    }
}
