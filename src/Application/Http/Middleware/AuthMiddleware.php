<?php

declare(strict_types=1);

namespace App\Application\Http\Middleware;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    /** @param callable(string): ?array $validateUserToken */
    /** @param callable(string): ?array $validateClinicToken */
    public function __construct(
        private readonly mixed $validateUserToken,
        private readonly mixed $validateClinicToken,
        private readonly array $publicRoutes = [
            '/up',
            '/docs',
            '/docs/ui',
            '/api/v1/auth/clinics',
            '/api/v1/auth/clinic/login',
            '/api/v1/auth/login',
            '/api/v1/auth/recovery/clinic',
            '/api/v1/auth/recovery/user',
            '/api/v1/auth/recovery/confirm',
        ],
        private readonly array $clinicRoutes = [
            '/api/v1/auth/staff',
            '/api/v1/auth/clinic/logout',
            '/api/v1/auth/login/pin',
        ]
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $uri = $request->getUri();

        if (in_array($uri, $this->publicRoutes, true)) {
            return $next($this->attachOptionalClinicSession($request));
        }

        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return ApiResponse::error($request, 401, 'Unauthorized', 'Missing or invalid Authorization header');
        }

        if (in_array($uri, $this->clinicRoutes, true)) {
            $clinicContext = ($this->validateClinicToken)($token);
            if ($clinicContext === null) {
                return ApiResponse::error($request, 401, 'Unauthorized', 'Invalid clinic token');
            }

            return $next(
                $request
                    ->withAttribute('clinic', $clinicContext)
                    ->withAttribute('clinic_access_token', $token)
            );
        }

        $userContext = ($this->validateUserToken)($token);
        if ($userContext === null) {
            return ApiResponse::error($request, 401, 'Unauthorized', 'Invalid token');
        }

        return $next(
            $request
                ->withAttribute('user', $userContext)
                ->withAttribute('access_token', $token)
        );
    }

    private function attachOptionalClinicSession(Request $request): Request
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $request;
        }

        $clinicContext = ($this->validateClinicToken)($token);
        if ($clinicContext === null) {
            return $request;
        }

        return $request
            ->withAttribute('clinic', $clinicContext)
            ->withAttribute('clinic_access_token', $token);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorization = $request->getHeader('authorization');
        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token !== '' ? $token : null;
    }
}
