<?php

namespace App\Modules\Auth\Handlers;

use App\Application\Audit\AuditRequestContext;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\AuthService;

final class LogoutHandler
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        $token = (string) $request->getAttribute('access_token', '');
        if ($token !== '') {
            $this->service->logoutUser($token, AuditRequestContext::fromRequest($request));
        }

        return ApiResponse::success($request, ['logged_out' => true]);
    }
}

