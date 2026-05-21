<?php

declare(strict_types=1);

use App\Application\Http\ApiDispatcher;
use App\Application\Http\JsonResponse;
use App\Application\Http\Middleware\AuthMiddleware;
use App\Application\Http\Middleware\LoggingMiddleware;
use App\Application\Http\Middleware\RequestIdMiddleware;
use App\Application\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Auth\TokenService;
use App\Application\ExitLogs\OpenExitLogLockAction;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\DatabaseConfig;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Mqtt\NoOpLockCommandPublisher;
use App\Infrastructure\Mqtt\PhpMqttLockCommandPublisher;
use App\Infrastructure\Persistence\PdoExitLogLockPort;
use App\Infrastructure\OpenAPI\OpenApiController;
use App\Infrastructure\Redis\RedisClient;
use App\Modules\Auth\Handlers\LoginHandler;
use App\Modules\Auth\Handlers\LogoutHandler;
use App\Modules\Auth\Mappers\AuthMapper;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Validators\LoginValidator;
use App\Modules\Clinic\Handlers\GetClinicHandler;
use App\Modules\Clinic\Handlers\PatchClinicSettingsHandler;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Clinic\Validators\ClinicSettingsValidator;
use App\Application\Stock\LocationValidator;
use App\Modules\EntryLogs\Handlers\CreateEntryLogHandler;
use App\Modules\EntryLogs\Handlers\ListEntryLogsHandler;
use App\Modules\EntryLogs\Services\EntryLogService;
use App\Modules\EntryLogs\Validators\EntryLogValidator;
use App\Modules\ExitLogs\Handlers\CancelExitLogHandler;
use App\Modules\ExitLogs\Handlers\ConfirmExitLogHandler;
use App\Modules\ExitLogs\Handlers\CreateExitLogHandler;
use App\Modules\ExitLogs\Handlers\GetExitLogHandler;
use App\Modules\ExitLogs\Handlers\ListExitLogsHandler;
use App\Modules\ExitLogs\Handlers\OpenExitLogLockHandler;
use App\Modules\ExitLogs\Handlers\PatchExitLogItemsHandler;
use App\Modules\ExitLogs\Services\ExitLogService;
use App\Modules\ExitLogs\Validators\ExitLogValidator;
use App\Modules\Products\Handlers\CreateProductHandler;
use App\Modules\Products\Handlers\DeleteProductHandler;
use App\Modules\Products\Handlers\GetProductHandler;
use App\Modules\Products\Handlers\GetProductStockLocationsHandler;
use App\Modules\Products\Handlers\ListProductsHandler;
use App\Modules\Products\Handlers\PatchProductHandler;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Validators\ProductValidator;
use App\Modules\Inventory\Handlers\ListInventoryHandler;
use App\Modules\Inventory\Handlers\UpsertInventoryHandler;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Validators\InventoryValidator;
use App\Modules\Incidents\Handlers\CreateIncidentHandler;
use App\Modules\Incidents\Handlers\ListIncidentsHandler;
use App\Modules\Incidents\Services\IncidentService;
use App\Modules\Incidents\Validators\IncidentValidator;
use App\Modules\Lockers\Handlers\CreateLockerHandler;
use App\Modules\Lockers\Handlers\DeleteLockerHandler;
use App\Modules\Lockers\Handlers\GetLockerHandler;
use App\Modules\Lockers\Handlers\ListLockersHandler;
use App\Modules\Lockers\Handlers\PatchLockerHandler;
use App\Modules\Lockers\Services\LockerService;
use App\Modules\Lockers\Validators\LockerValidator;
use App\Modules\Compartments\Handlers\CreateCompartmentHandler;
use App\Modules\Compartments\Handlers\DeleteCompartmentHandler;
use App\Modules\Compartments\Handlers\GetCompartmentHandler;
use App\Modules\Compartments\Handlers\ListCompartmentsHandler;
use App\Modules\Compartments\Handlers\PatchCompartmentHandler;
use App\Modules\Compartments\Services\CompartmentService;
use App\Modules\Compartments\Validators\CompartmentValidator;
use App\Modules\Settings\Handlers\ListSettingsHandler;
use App\Modules\Settings\Handlers\UpsertSettingHandler;
use App\Modules\Settings\Services\SettingService;
use App\Modules\Settings\Validators\SettingValidator;
use App\Modules\Users\Handlers\CreateUserHandler;
use App\Modules\Users\Handlers\DeleteUserHandler;
use App\Modules\Users\Handlers\GetUserHandler;
use App\Modules\Users\Handlers\ListUsersHandler;
use App\Modules\Users\Handlers\PatchUserHandler;
use App\Modules\Users\Services\UserService;
use App\Modules\Users\Validators\UserValidator;

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

$pdo = Connection::create(
    $dbConfig['host'],
    $dbConfig['port'],
    $dbConfig['database'],
    $dbConfig['username'],
    $dbConfig['password']
);

$redis = new RedisClient(
    (string) $config->get('redis.host'),
    (int) $config->get('redis.port')
);

$logger = LoggerFactory::create('erp-api');
$tokenService = new TokenService($redis, 1800);
$authMapper = new AuthMapper();
$authService = new AuthService($pdo, $tokenService, $authMapper);
$loginValidator = new LoginValidator();
$loginHandler = new LoginHandler($loginValidator, $authService);
$logoutHandler = new LogoutHandler($authService);
$inventoryService = new InventoryService($pdo);
$inventoryValidator = new InventoryValidator();
$listInventoryHandler = new ListInventoryHandler($inventoryService);
$upsertInventoryHandler = new UpsertInventoryHandler($inventoryValidator, $inventoryService);
$locationValidator = new LocationValidator($pdo);
$entryLogService = new EntryLogService($pdo, $locationValidator);
$entryLogValidator = new EntryLogValidator($locationValidator);
$createEntryLogHandler = new CreateEntryLogHandler($entryLogValidator, $entryLogService);
$listEntryLogsHandler = new ListEntryLogsHandler($entryLogService);
$exitLogService = new ExitLogService($pdo, $locationValidator);
$exitLogValidator = new ExitLogValidator($locationValidator);
$createExitLogHandler = new CreateExitLogHandler($exitLogValidator, $exitLogService);
$listExitLogsHandler = new ListExitLogsHandler($exitLogService);
$getExitLogHandler = new GetExitLogHandler($exitLogService);
$patchExitLogItemsHandler = new PatchExitLogItemsHandler($exitLogValidator, $exitLogService);
$confirmExitLogHandler = new ConfirmExitLogHandler($exitLogService);
$cancelExitLogHandler = new CancelExitLogHandler($exitLogService);
$exitLogLockPort = new PdoExitLogLockPort($pdo);
$mqttDisabled = filter_var($_ENV['MQTT_DISABLED'] ?? 'false', FILTER_VALIDATE_BOOL);
$mqttHost = (string) $config->get('mqtt.host', '');
$lockCommandPublisher = ($mqttHost !== '' && !$mqttDisabled)
    ? new PhpMqttLockCommandPublisher($config, $logger)
    : new NoOpLockCommandPublisher();
$openExitLogLockAction = new OpenExitLogLockAction($exitLogLockPort, $lockCommandPublisher, $logger);
$openExitLogLockHandler = new OpenExitLogLockHandler($openExitLogLockAction);
$incidentService = new IncidentService($pdo);
$incidentValidator = new IncidentValidator();
$createIncidentHandler = new CreateIncidentHandler($incidentValidator, $incidentService);
$listIncidentsHandler = new ListIncidentsHandler($incidentService);
$settingService = new SettingService($pdo);
$settingValidator = new SettingValidator();
$upsertSettingHandler = new UpsertSettingHandler($settingValidator, $settingService);
$listSettingsHandler = new ListSettingsHandler($settingService);
$clinicService = new ClinicService($pdo);
$getClinicHandler = new GetClinicHandler($clinicService);
$clinicSettingsValidator = new ClinicSettingsValidator();
$patchClinicSettingsHandler = new PatchClinicSettingsHandler($clinicSettingsValidator, $settingService);
$productService = new ProductService($pdo);
$productValidator = new ProductValidator();
$listProductsHandler = new ListProductsHandler($productService);
$getProductHandler = new GetProductHandler($productService);
$getProductStockLocationsHandler = new GetProductStockLocationsHandler($inventoryService);
$createProductHandler = new CreateProductHandler($productValidator, $productService);
$patchProductHandler = new PatchProductHandler($productValidator, $productService);
$deleteProductHandler = new DeleteProductHandler($productService);
$userService = new UserService($pdo);
$userValidator = new UserValidator();
$listUsersHandler = new ListUsersHandler($userService);
$getUserHandler = new GetUserHandler($userService);
$createUserHandler = new CreateUserHandler($userValidator, $userService);
$patchUserHandler = new PatchUserHandler($userValidator, $userService);
$deleteUserHandler = new DeleteUserHandler($userService);
$lockerService = new LockerService($pdo);
$lockerValidator = new LockerValidator();
$listLockersHandler = new ListLockersHandler($lockerService);
$getLockerHandler = new GetLockerHandler($lockerService);
$createLockerHandler = new CreateLockerHandler($lockerValidator, $lockerService);
$patchLockerHandler = new PatchLockerHandler($lockerValidator, $lockerService);
$deleteLockerHandler = new DeleteLockerHandler($lockerService);
$compartmentService = new CompartmentService($pdo);
$compartmentValidator = new CompartmentValidator();
$listCompartmentsHandler = new ListCompartmentsHandler($compartmentService);
$getCompartmentHandler = new GetCompartmentHandler($compartmentService);
$createCompartmentHandler = new CreateCompartmentHandler($compartmentValidator, $compartmentService);
$patchCompartmentHandler = new PatchCompartmentHandler($compartmentValidator, $compartmentService);
$deleteCompartmentHandler = new DeleteCompartmentHandler($compartmentService);
$openApiController = new OpenApiController();

$router = new Router();
$router->addRoute('GET', '/up', fn ($request) => \App\Application\Http\ApiResponse::success($request, ['status' => 'up']));
$router->addRoute('POST', '/api/v1/auth/login', fn ($request) => $loginHandler($request));
$router->addRoute('POST', '/api/v1/auth/logout', fn ($request) => $logoutHandler($request));
$router->addRoute('GET', '/api/v1/me', function ($request) {
    $user = (array) $request->getAttribute('user', []);

    return \App\Application\Http\ApiResponse::success($request, [
        'id' => (string) ($user['user_id'] ?? $user['id'] ?? ''),
        'clinic_id' => (string) ($user['clinic_id'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
    ]);
});
$router->addRoute('GET', '/api/v1/clinic', fn ($request) => $getClinicHandler($request));
$router->addRoute('PATCH', '/api/v1/clinic/settings', fn ($request) => $patchClinicSettingsHandler($request));
$router->addRoute('GET', '/api/v1/products', fn ($request) => $listProductsHandler($request));
$router->addRoute('GET', '/api/v1/products/{product_id}/stock-locations', fn ($request) => $getProductStockLocationsHandler($request));
$router->addRoute('GET', '/api/v1/products/{product_id}', fn ($request) => $getProductHandler($request));
$router->addRoute('POST', '/api/v1/products', fn ($request) => $createProductHandler($request));
$router->addRoute('PATCH', '/api/v1/products/{product_id}', fn ($request) => $patchProductHandler($request));
$router->addRoute('DELETE', '/api/v1/products/{product_id}', fn ($request) => $deleteProductHandler($request));
$router->addRoute('GET', '/api/v1/users', fn ($request) => $listUsersHandler($request));
$router->addRoute('GET', '/api/v1/users/{user_id}', fn ($request) => $getUserHandler($request));
$router->addRoute('POST', '/api/v1/users', fn ($request) => $createUserHandler($request));
$router->addRoute('PATCH', '/api/v1/users/{user_id}', fn ($request) => $patchUserHandler($request));
$router->addRoute('DELETE', '/api/v1/users/{user_id}', fn ($request) => $deleteUserHandler($request));
$router->addRoute('GET', '/api/v1/lockers', fn ($request) => $listLockersHandler($request));
$router->addRoute('GET', '/api/v1/lockers/{locker_id}', fn ($request) => $getLockerHandler($request));
$router->addRoute('POST', '/api/v1/lockers', fn ($request) => $createLockerHandler($request));
$router->addRoute('PATCH', '/api/v1/lockers/{locker_id}', fn ($request) => $patchLockerHandler($request));
$router->addRoute('DELETE', '/api/v1/lockers/{locker_id}', fn ($request) => $deleteLockerHandler($request));
$router->addRoute('GET', '/api/v1/compartments', fn ($request) => $listCompartmentsHandler($request));
$router->addRoute('GET', '/api/v1/compartments/{compartment_id}', fn ($request) => $getCompartmentHandler($request));
$router->addRoute('POST', '/api/v1/compartments', fn ($request) => $createCompartmentHandler($request));
$router->addRoute('PATCH', '/api/v1/compartments/{compartment_id}', fn ($request) => $patchCompartmentHandler($request));
$router->addRoute('DELETE', '/api/v1/compartments/{compartment_id}', fn ($request) => $deleteCompartmentHandler($request));
$router->addRoute('GET', '/api/v1/inventory', fn ($request) => $listInventoryHandler($request));
$router->addRoute('GET', '/api/v1/entry-logs', fn ($request) => $listEntryLogsHandler($request));
$router->addRoute('POST', '/api/v1/entry-logs', fn ($request) => $createEntryLogHandler($request));
$router->addRoute('GET', '/api/v1/exit-logs', fn ($request) => $listExitLogsHandler($request));
$router->addRoute('POST', '/api/v1/exit-logs', fn ($request) => $createExitLogHandler($request));
$router->addRoute('GET', '/api/v1/exit-logs/{id}', fn ($request) => $getExitLogHandler($request));
$router->addRoute('PATCH', '/api/v1/exit-logs/{id}', fn ($request) => $patchExitLogItemsHandler($request));
$router->addRoute('POST', '/api/v1/exit-logs/{id}/confirm', fn ($request) => $confirmExitLogHandler($request));
$router->addRoute('POST', '/api/v1/exit-logs/{id}/cancel', fn ($request) => $cancelExitLogHandler($request));
$router->addRoute('POST', '/api/v1/exit-logs/{id}/open-lock', fn ($request) => $openExitLogLockHandler($request));
$router->addRoute('GET', '/api/v1/incidents', fn ($request) => $listIncidentsHandler($request));
$router->addRoute('POST', '/api/v1/incidents', fn ($request) => $createIncidentHandler($request));
$router->addRoute('GET', '/api/v1/settings', fn ($request) => $listSettingsHandler($request));
$router->addRoute('POST', '/api/v1/settings', fn ($request) => $upsertSettingHandler($request));
$router->addRoute('GET', '/docs', fn () => $openApiController->docsYaml());
$router->addRoute('GET', '/docs/ui', fn () => $openApiController->docsUi());

$roleRules = [
    'GET /api/v1/me' => ['STAFF'],
    'POST /api/v1/auth/logout' => ['STAFF'],
    'GET /api/v1/clinic' => ['STAFF'],
    'PATCH /api/v1/clinic/settings' => ['ADMIN'],
    'GET /api/v1/products' => ['STAFF'],
    're:/^GET \\/api\\/v1\\/products\\/[^\\/]+\\/stock-locations$/' => ['STAFF'],
    're:/^GET \\/api\\/v1\\/products\\/[^\\/]+$/' => ['STAFF'],
    'POST /api/v1/products' => ['TECHNICIAN'],
    're:/^PATCH \\/api\\/v1\\/products\\/[^\\/]+$/' => ['TECHNICIAN'],
    're:/^DELETE \\/api\\/v1\\/products\\/[^\\/]+$/' => ['ADMIN'],
    'GET /api/v1/users' => ['ADMIN'],
    're:/^GET \\/api\\/v1\\/users\\/[^\\/]+$/' => ['ADMIN'],
    'POST /api/v1/users' => ['ADMIN'],
    're:/^PATCH \\/api\\/v1\\/users\\/[^\\/]+$/' => ['ADMIN'],
    're:/^DELETE \\/api\\/v1\\/users\\/[^\\/]+$/' => ['ADMIN'],
    'GET /api/v1/lockers' => ['STAFF'],
    're:/^GET \\/api\\/v1\\/lockers\\/[^\\/]+$/' => ['STAFF'],
    'POST /api/v1/lockers' => ['TECHNICIAN'],
    're:/^PATCH \\/api\\/v1\\/lockers\\/[^\\/]+$/' => ['TECHNICIAN'],
    're:/^DELETE \\/api\\/v1\\/lockers\\/[^\\/]+$/' => ['ADMIN'],
    'GET /api/v1/compartments' => ['STAFF'],
    're:/^GET \\/api\\/v1\\/compartments\\/[^\\/]+$/' => ['STAFF'],
    'POST /api/v1/compartments' => ['TECHNICIAN'],
    're:/^PATCH \\/api\\/v1\\/compartments\\/[^\\/]+$/' => ['TECHNICIAN'],
    're:/^DELETE \\/api\\/v1\\/compartments\\/[^\\/]+$/' => ['ADMIN'],
    'GET /api/v1/inventory' => ['STAFF'],
    'GET /api/v1/entry-logs' => ['STAFF'],
    'POST /api/v1/entry-logs' => ['TECHNICIAN'],
    'GET /api/v1/exit-logs' => ['STAFF'],
    'POST /api/v1/exit-logs' => ['STAFF'],
    're:/^GET \\/api\\/v1\\/exit-logs\\/[^\\/]+$/' => ['STAFF'],
    're:/^PATCH \\/api\\/v1\\/exit-logs\\/[^\\/]+$/' => ['STAFF'],
    're:/^POST \\/api\\/v1\\/exit-logs\\/[^\\/]+\\/confirm$/' => ['STAFF'],
    're:/^POST \\/api\\/v1\\/exit-logs\\/[^\\/]+\\/cancel$/' => ['STAFF'],
    're:/^POST \\/api\\/v1\\/exit-logs\\/[^\\/]+\\/open-lock$/' => ['STAFF'],
    'GET /api/v1/incidents' => ['TECHNICIAN'],
    'POST /api/v1/incidents' => ['TECHNICIAN'],
    'GET /api/v1/settings' => ['STAFF'],
    'POST /api/v1/settings' => ['ADMIN'],
];

$dispatcher = new ApiDispatcher(
    fn (string $method, string $path): array => $router->dispatch($method, $path),
    [
    new RequestIdMiddleware(),
    new LoggingMiddleware($logger),
    new AuthMiddleware(fn (string $token): ?array => $authService->validateToken($token)),
    new RoleMiddleware($roleRules),
]
);

return [
    'dispatcher' => $dispatcher,
    'pdo' => $pdo,
];
