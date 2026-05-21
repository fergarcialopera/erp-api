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
use App\Application\Support\PublicUrlBuilder;
use App\Infrastructure\Auth\LoginAttemptService;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Storage\LocalImageStorage;
use App\Modules\Auth\Handlers\ClinicLoginHandler;
use App\Modules\Auth\Handlers\ClinicLogoutHandler;
use App\Modules\Auth\Handlers\ConfirmRecoveryHandler;
use App\Modules\Auth\Handlers\ListAuthClinicsHandler;
use App\Modules\Auth\Handlers\ListAuthStaffHandler;
use App\Modules\Auth\Handlers\LoginHandler;
use App\Modules\Auth\Handlers\LogoutHandler;
use App\Modules\Auth\Handlers\PinLoginHandler;
use App\Modules\Auth\Handlers\RequestRecoveryClinicHandler;
use App\Modules\Auth\Handlers\RequestRecoveryUserHandler;
use App\Modules\Auth\Mappers\AuthMapper;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\RecoveryService;
use App\Modules\Auth\Validators\ClinicLoginValidator;
use App\Modules\Auth\Validators\LoginValidator;
use App\Modules\Auth\Validators\PinLoginValidator;
use App\Modules\Auth\Validators\RecoveryValidator;
use App\Modules\Clinic\Handlers\DeleteClinicImageHandler;
use App\Modules\Clinic\Handlers\GetClinicHandler;
use App\Modules\Clinic\Handlers\PatchClinicHandler;
use App\Modules\Clinic\Handlers\PatchClinicSettingsHandler;
use App\Modules\Clinic\Handlers\RequestClinicRecoveryHandler;
use App\Modules\Clinic\Handlers\UploadClinicImageHandler;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Clinic\Validators\ClinicSettingsValidator;
use App\Modules\Clinic\Validators\ClinicValidator;
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
use App\Modules\Inventory\Handlers\PatchInventoryProductHandler;
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
use App\Modules\Lockers\Handlers\ListLockersWithCompartmentsHandler;
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
use App\Modules\Users\Handlers\DeleteUserImageHandler;
use App\Modules\Users\Handlers\GetUserHandler;
use App\Modules\Users\Handlers\ListUsersHandler;
use App\Modules\Users\Handlers\PatchUserHandler;
use App\Modules\Users\Handlers\SendUserRecoveryHandler;
use App\Modules\Users\Handlers\UploadUserImageHandler;
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
$projectRoot = dirname(__DIR__);
$publicBaseUrl = rtrim((string) ($_ENV['APP_PUBLIC_URL'] ?? 'http://localhost:8080'), '/');
$frontendBaseUrl = rtrim((string) ($_ENV['FRONTEND_URL'] ?? 'http://localhost:3000'), '/');
$publicUrls = new PublicUrlBuilder($publicBaseUrl);
$userTtl = (int) ($_ENV['AUTH_USER_TTL'] ?? 1800);
$clinicTtl = (int) ($_ENV['AUTH_CLINIC_TTL'] ?? 28800);
$tokenService = new TokenService($redis, $userTtl, $clinicTtl);
$loginAttempts = new LoginAttemptService($redis);
$authMapper = new AuthMapper($publicUrls);
$authService = new AuthService($pdo, $tokenService, $loginAttempts, $authMapper);
$mailer = null;
$mailHost = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
if ($mailHost !== '') {
    $mailer = new SmtpMailer(
        $mailHost,
        (int) ($_ENV['MAIL_PORT'] ?? 1025),
        (string) ($_ENV['MAIL_FROM'] ?? 'noreply@erp.local'),
        (string) ($_ENV['MAIL_FROM_NAME'] ?? 'ERP Clinic')
    );
}
$recoveryService = new RecoveryService(
    $pdo,
    $mailer,
    $frontendBaseUrl,
    (int) ($_ENV['RECOVERY_TTL_MINUTES'] ?? 60)
);
$imageStorage = new LocalImageStorage($projectRoot);
$loginValidator = new LoginValidator();
$clinicLoginValidator = new ClinicLoginValidator();
$pinLoginValidator = new PinLoginValidator();
$recoveryValidator = new RecoveryValidator();
$loginHandler = new LoginHandler($loginValidator, $authService);
$logoutHandler = new LogoutHandler($authService);
$listAuthClinicsHandler = new ListAuthClinicsHandler($authService);
$clinicLoginHandler = new ClinicLoginHandler($clinicLoginValidator, $authService);
$clinicLogoutHandler = new ClinicLogoutHandler($authService);
$listAuthStaffHandler = new ListAuthStaffHandler($authService);
$pinLoginHandler = new PinLoginHandler($pinLoginValidator, $authService);
$requestRecoveryClinicHandler = new RequestRecoveryClinicHandler($recoveryValidator, $recoveryService);
$requestRecoveryUserHandler = new RequestRecoveryUserHandler($recoveryValidator, $recoveryService);
$confirmRecoveryHandler = new ConfirmRecoveryHandler($recoveryValidator, $recoveryService);
$locationValidator = new LocationValidator($pdo);
$inventoryService = new InventoryService($pdo);
$inventoryValidator = new InventoryValidator($locationValidator);
$listInventoryHandler = new ListInventoryHandler($inventoryService);
$patchInventoryProductHandler = new PatchInventoryProductHandler($inventoryValidator, $inventoryService);
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
$clinicService = new ClinicService($pdo, $publicUrls);
$getClinicHandler = new GetClinicHandler($clinicService);
$clinicValidator = new ClinicValidator();
$patchClinicHandler = new PatchClinicHandler($clinicValidator, $clinicService);
$uploadClinicImageHandler = new UploadClinicImageHandler($clinicService, $imageStorage);
$deleteClinicImageHandler = new DeleteClinicImageHandler($clinicService, $imageStorage);
$requestClinicRecoveryHandler = new RequestClinicRecoveryHandler($recoveryValidator, $recoveryService);
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
$userService = new UserService($pdo, $publicUrls, $loginAttempts);
$userValidator = new UserValidator();
$listUsersHandler = new ListUsersHandler($userService);
$getUserHandler = new GetUserHandler($userService);
$createUserHandler = new CreateUserHandler($userValidator, $userService);
$patchUserHandler = new PatchUserHandler($userValidator, $userService);
$deleteUserHandler = new DeleteUserHandler($userService);
$sendUserRecoveryHandler = new SendUserRecoveryHandler($userValidator, $recoveryService);
$uploadUserImageHandler = new UploadUserImageHandler($userService, $imageStorage);
$deleteUserImageHandler = new DeleteUserImageHandler($userService, $imageStorage);
$lockerService = new LockerService($pdo);
$lockerValidator = new LockerValidator();
$listLockersHandler = new ListLockersHandler($lockerService);
$listLockersWithCompartmentsHandler = new ListLockersWithCompartmentsHandler($lockerService);
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
$router->addRoute('GET', '/api/v1/auth/clinics', fn ($request) => $listAuthClinicsHandler($request));
$router->addRoute('POST', '/api/v1/auth/clinic/login', fn ($request) => $clinicLoginHandler($request));
$router->addRoute('POST', '/api/v1/auth/clinic/logout', fn ($request) => $clinicLogoutHandler($request));
$router->addRoute('GET', '/api/v1/auth/staff', fn ($request) => $listAuthStaffHandler($request));
$router->addRoute('POST', '/api/v1/auth/login/pin', fn ($request) => $pinLoginHandler($request));
$router->addRoute('POST', '/api/v1/auth/login', fn ($request) => $loginHandler($request));
$router->addRoute('POST', '/api/v1/auth/logout', fn ($request) => $logoutHandler($request));
$router->addRoute('POST', '/api/v1/auth/recovery/clinic', fn ($request) => $requestRecoveryClinicHandler($request));
$router->addRoute('POST', '/api/v1/auth/recovery/user', fn ($request) => $requestRecoveryUserHandler($request));
$router->addRoute('POST', '/api/v1/auth/recovery/confirm', fn ($request) => $confirmRecoveryHandler($request));
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
$router->addRoute('PATCH', '/api/v1/clinic', fn ($request) => $patchClinicHandler($request));
$router->addRoute('POST', '/api/v1/clinic/image', fn ($request) => $uploadClinicImageHandler($request));
$router->addRoute('DELETE', '/api/v1/clinic/image', fn ($request) => $deleteClinicImageHandler($request));
$router->addRoute('POST', '/api/v1/clinic/recovery', fn ($request) => $requestClinicRecoveryHandler($request));
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
$router->addRoute('POST', '/api/v1/users/{user_id}/recovery', fn ($request) => $sendUserRecoveryHandler($request));
$router->addRoute('POST', '/api/v1/users/{user_id}/image', fn ($request) => $uploadUserImageHandler($request));
$router->addRoute('DELETE', '/api/v1/users/{user_id}/image', fn ($request) => $deleteUserImageHandler($request));
$router->addRoute('DELETE', '/api/v1/users/{user_id}', fn ($request) => $deleteUserHandler($request));
$router->addRoute('GET', '/api/v1/lockers', fn ($request) => $listLockersHandler($request));
$router->addRoute('GET', '/api/v1/lockers/tree', fn ($request) => $listLockersWithCompartmentsHandler($request));
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
$router->addRoute('PATCH', '/api/v1/inventory/products/{product_id}', fn ($request) => $patchInventoryProductHandler($request));
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
    'PATCH /api/v1/clinic' => ['ADMIN'],
    'POST /api/v1/clinic/image' => ['ADMIN'],
    'DELETE /api/v1/clinic/image' => ['ADMIN'],
    'POST /api/v1/clinic/recovery' => ['ADMIN'],
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
    're:/^POST \\/api\\/v1\\/users\\/[^\\/]+\\/recovery$/' => ['ADMIN'],
    're:/^POST \\/api\\/v1\\/users\\/[^\\/]+\\/image$/' => ['ADMIN'],
    're:/^DELETE \\/api\\/v1\\/users\\/[^\\/]+\\/image$/' => ['ADMIN'],
    're:/^DELETE \\/api\\/v1\\/users\\/[^\\/]+$/' => ['ADMIN'],
    'GET /api/v1/lockers' => ['STAFF'],
    'GET /api/v1/lockers/tree' => ['STAFF'],
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
    're:/^PATCH \\/api\\/v1\\/inventory\\/products\\/[^\\/]+$/' => ['ADMIN'],
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
    'GET /api/v1/settings' => ['ADMIN'],
    'POST /api/v1/settings' => ['ADMIN'],
];

$dispatcher = new ApiDispatcher(
    fn (string $method, string $path): array => $router->dispatch($method, $path),
    [
    new RequestIdMiddleware(),
    new LoggingMiddleware($logger),
    new AuthMiddleware(
        fn (string $token): ?array => $authService->validateUserToken($token),
        fn (string $token): ?array => $authService->validateClinicToken($token)
    ),
    new RoleMiddleware($roleRules),
]
);

return [
    'dispatcher' => $dispatcher,
    'pdo' => $pdo,
];
