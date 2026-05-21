<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\AuthService;

final class ClinicLogoutHandler
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        $token = (string) $request->getAttribute('clinic_access_token', '');
        if ($token !== '') {
            $this->service->logoutClinic($token);
        }

        return ApiResponse::success($request, ['logged_out' => true]);
    }
}
