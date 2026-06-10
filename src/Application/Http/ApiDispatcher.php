<?php

namespace App\Application\Http;

use App\Application\Http\Middleware\MiddlewareInterface;
use Closure;
use FastRoute\Dispatcher;
use Throwable;

final class ApiDispatcher
{
    /**
     * @param array<int, MiddlewareInterface> $middlewares
     */
    public function __construct(
        private readonly Closure $routeDispatcher,
        private readonly array $middlewares = []
    ) {
    }

    public function dispatch(Request $request): Response
    {
        $routeInfo = ($this->routeDispatcher)($request->getMethod(), $request->getUri());

        if ($routeInfo[0] === Dispatcher::NOT_FOUND) {
            return ApiResponse::error($request, 404, 'Not Found', 'Route not found');
        }

        if ($routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return ApiResponse::error($request, 405, 'Method Not Allowed', 'Method not allowed');
        }

        $handler = $routeInfo[1];
        $vars = is_array($routeInfo[2] ?? null) ? $routeInfo[2] : [];

        foreach ($vars as $key => $value) {
            $request = $request->withAttribute((string) $key, $value);
        }

        $core = fn (Request $req): Response => $handler($req);
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            fn (callable $next, MiddlewareInterface $middleware): callable =>
                fn (Request $req): Response => $middleware->process($req, $next),
            $core
        );

        try {
            return $pipeline($request);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
