<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\AuthService;
use Throwable;

final class ListAuthClinicsHandler
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            return ApiResponse::success($request, $this->service->listVisibleClinics());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
