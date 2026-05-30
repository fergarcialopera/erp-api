<?php

declare(strict_types=1);

use App\Application\Http\ApiDispatcher;
use App\Application\Http\Middleware\AuthMiddleware;
use App\Application\Http\Middleware\LoggingMiddleware;
use App\Application\Http\Middleware\RequestIdMiddleware;
use App\Application\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\DatabaseConfig;
use App\Infrastructure\Http\Router;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    $dotenvClass = '\Dotenv\Dotenv';
    if (class_exists($dotenvClass)) {
        $dotenvClass::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

$dbConfig = DatabaseConfig::fromEnvironment();

$config = new Config([
    'db.host' => $dbConfig['host'],
    'db.port' => $dbConfig['port'],
    'db.database' => $dbConfig['database'],
    'db.username' => $dbConfig['username'],
    'db.password' => $dbConfig['password'],
    'redis.host' => $_ENV['REDIS_HOST'] ?? 'redis',
    'redis.port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
    'mqtt.host' => trim((string) ($_ENV['MQTT_HOST'] ?? '')),
    'mqtt.port' => (int) ($_ENV['MQTT_PORT'] ?? 1883),
    'mqtt.username' => $_ENV['MQTT_USERNAME'] ?? null,
    'mqtt.password' => $_ENV['MQTT_PASSWORD'] ?? null,
    'mqtt.client_id' => trim((string) ($_ENV['MQTT_CLIENT_ID'] ?? 'erp-backend')),
]);

$buildServices = require __DIR__ . '/services.php';
$app = $buildServices($config, $dbConfig);

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
