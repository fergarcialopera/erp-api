<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Auth\RequestClinicResolver;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use Throwable;

final class GetClinicHandler
{
    public function __construct(
        private readonly RequestClinicResolver $clinicResolver,
        private readonly ClinicService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = $this->clinicResolver->requireClinicId($request, $user);
            $clinic = $this->service->getById($clinicId);
            if ($clinic === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            return ApiResponse::success($request, $clinic);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
