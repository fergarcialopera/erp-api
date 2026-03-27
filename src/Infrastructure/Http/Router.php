<?php

namespace App\Infrastructure\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [strtoupper($method), $path, $handler];
    }

    public function dispatch(string $method, string $path): array
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as [$httpMethod, $routePath, $handler]) {
                $collector->addRoute($httpMethod, $routePath, $handler);
            }
        });

        return $dispatcher->dispatch($method, $path);
    }
}
