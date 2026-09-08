<?php

declare(strict_types=1);

namespace App\Modules\Specialties\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Specialties\Services\SpecialtyService;
use Throwable;

final class GetSpecialtyHandler
{
    public function __construct(private readonly SpecialtyService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('specialty_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Specialty not found');
            }

            $specialty = $this->service->get($id);
            if ($specialty === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Specialty not found');
            }

            return ApiResponse::success($request, $specialty);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
