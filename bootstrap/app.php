<?php

declare(strict_types=1);

use App\Application\Http\ApiDispatcher;
use App\Application\Http\Middleware\AuthMiddleware;
use App\Application\Http\Middleware\LoggingMiddleware;
use App\Application\Http\Middleware\RequestIdMiddleware;
use App\Application\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Config\ApplicationConfig;
use App\Infrastructure\Http\Router;

require __DIR__ . '/load-env.php';

$appConfig = ApplicationConfig::load();

$buildServices = require __DIR__ . '/services.php';
$app = $buildServices($appConfig);

$router = new Router();
$registerRoutes = require __DIR__ . '/routes.php';
$roleRules = $registerRoutes($router, $app['handlers']);

$dispatcher = new ApiDispatcher(
    fn (string $method, string $path): array => $router->dispatch($method, $path),
    [
        new RequestIdMiddleware(),
        new LoggingMiddleware($app['logger']),
        new AuthMiddleware(
            fn (string $token): ?array => $app['authService']->validateUserToken($token),
            fn (string $token): ?array => $app['authService']->validateClinicToken($token)
        ),
        new RoleMiddleware($roleRules),
    ]
);

return [
    'dispatcher' => $dispatcher,
    'pdo' => $app['pdo'],
];
