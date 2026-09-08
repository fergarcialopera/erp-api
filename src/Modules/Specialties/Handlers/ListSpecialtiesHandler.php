<?php

declare(strict_types=1);

namespace App\Modules\Specialties\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Specialties\Services\SpecialtyService;
use Throwable;

final class ListSpecialtiesHandler
{
    public function __construct(private readonly SpecialtyService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $qp = $request->getQueryParams();
            $active = null;
            if (array_key_exists('active', $qp)) {
                $bool = filter_var($qp['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Invalid active filter');
                }
                $active = (bool) $bool;
            }

            return ApiResponse::success($request, $this->service->list($active));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
