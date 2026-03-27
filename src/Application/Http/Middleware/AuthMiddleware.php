<?php

namespace App\Application\Http\Middleware;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use Closure;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Closure $authenticateToken,
        private readonly array $publicRoutes = ['/up', '/api/v1/auth/login', '/docs', '/docs/ui']
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->getUri(), $this->publicRoutes, true)) {
            return $next($request);
        }

        $authorization = $request->getHeader('authorization');
        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return ApiResponse::error($request, 401, 'Unauthorized', 'Missing or invalid Authorization header');
        }

        $token = trim(substr($authorization, 7));
        $userContext = ($this->authenticateToken)($token);
        if ($userContext === null) {
            return ApiResponse::error($request, 401, 'Unauthorized', 'Invalid token');
        }

        return $next(
            $request
                ->withAttribute('user', $userContext)
                ->withAttribute('access_token', $token)
        );
    }
}
