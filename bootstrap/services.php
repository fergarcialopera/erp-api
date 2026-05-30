<?php

declare(strict_types=1);

use App\Application\ExitLogs\OpenExitLogLockAction;
use App\Application\Stock\LocationValidator;
use App\Application\Support\PublicUrlBuilder;
use App\Infrastructure\Auth\LoginAttemptService;
use App\Infrastructure\Auth\TokenService;
use App\Infrastructure\Config\ApplicationConfig;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Mqtt\NoOpLockCommandPublisher;
use App\Infrastructure\Mqtt\PhpMqttLockCommandPublisher;
use App\Infrastructure\OpenAPI\OpenApiController;
use App\Infrastructure\Persistence\PdoExitLogLockPort;
use App\Infrastructure\Redis\RedisClient;
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
use App\Modules\Compartments\Handlers\CreateCompartmentHandler;
use App\Modules\Compartments\Handlers\DeleteCompartmentHandler;
use App\Modules\Compartments\Handlers\GetCompartmentHandler;
use App\Modules\Compartments\Handlers\ListCompartmentsHandler;
use App\Modules\Compartments\Handlers\PatchCompartmentHandler;
use App\Modules\Compartments\Services\CompartmentService;
use App\Modules\Compartments\Validators\CompartmentValidator;
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
use App\Modules\Incidents\Handlers\CreateIncidentHandler;
use App\Modules\Incidents\Handlers\ListIncidentsHandler;
use App\Modules\Incidents\Services\IncidentService;
use App\Modules\Incidents\Validators\IncidentValidator;
use App\Modules\Inventory\Handlers\ListInventoryHandler;
use App\Modules\Inventory\Handlers\PatchInventoryProductHandler;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Validators\InventoryValidator;
use App\Modules\Lockers\Handlers\CreateLockerHandler;
use App\Modules\Lockers\Handlers\DeleteLockerHandler;
use App\Modules\Lockers\Handlers\GetLockerHandler;
use App\Modules\Lockers\Handlers\ListLockersHandler;
use App\Modules\Lockers\Handlers\ListLockersWithCompartmentsHandler;
use App\Modules\Lockers\Handlers\PatchLockerHandler;
use App\Modules\Lockers\Services\LockerService;
use App\Modules\Lockers\Validators\LockerValidator;
use App\Modules\Products\Handlers\CreateProductHandler;
use App\Modules\Products\Handlers\DeleteProductHandler;
use App\Modules\Products\Handlers\GetProductHandler;
use App\Modules\Products\Handlers\GetProductStockLocationsHandler;
use App\Modules\Products\Handlers\ListProductsHandler;
use App\Modules\Products\Handlers\PatchProductHandler;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Validators\ProductValidator;
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
use Psr\Log\LoggerInterface;

/**
 * @return array{
 *     pdo: \PDO,
 *     logger: LoggerInterface,
 *     authService: AuthService,
 *     handlers: array<string, callable|OpenApiController>
 * }
 */
return static function (ApplicationConfig $appConfig): array {
    $dbConfig = $appConfig->database();
    $config = $appConfig->infrastructure();

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
    $publicUrls = new PublicUrlBuilder($appConfig->publicBaseUrl());
    $userTtl = $appConfig->authUserTtl();
    $clinicTtl = $appConfig->authClinicTtl();
    $tokenService = new TokenService($redis, $userTtl, $clinicTtl);
    $loginAttempts = new LoginAttemptService($redis);
    $authMapper = new AuthMapper($publicUrls);
    $authService = new AuthService($pdo, $tokenService, $loginAttempts, $authMapper);

    $mailer = null;
    $mailHost = $appConfig->mailHost();
    if ($mailHost !== '') {
        $mailer = new SmtpMailer(
            $mailHost,
            $appConfig->mailPort(),
            $appConfig->mailFrom(),
            $appConfig->mailFromName()
        );
    }

    $recoveryService = new RecoveryService(
        $pdo,
        $mailer,
        $appConfig->frontendBaseUrl(),
        $appConfig->recoveryTtlMinutes()
    );
    $imageStorage = new LocalImageStorage($projectRoot);
    $locationValidator = new LocationValidator($pdo);

    $inventoryService = new InventoryService($pdo);
    $inventoryValidator = new InventoryValidator($locationValidator);

    $entryLogService = new EntryLogService($pdo, $locationValidator);
    $entryLogValidator = new EntryLogValidator($locationValidator);

    $exitLogService = new ExitLogService($pdo, $locationValidator);
    $exitLogValidator = new ExitLogValidator($locationValidator);
    $exitLogLockPort = new PdoExitLogLockPort($pdo);
    $mqttHost = $appConfig->mqttHost();
    $lockCommandPublisher = ($mqttHost !== '' && !$appConfig->mqttDisabled())
        ? new PhpMqttLockCommandPublisher($config, $logger)
        : new NoOpLockCommandPublisher();
    $openExitLogLockAction = new OpenExitLogLockAction($exitLogLockPort, $lockCommandPublisher, $logger);

    $incidentService = new IncidentService($pdo);
    $settingService = new SettingService($pdo);
    $clinicService = new ClinicService($pdo, $publicUrls);
    $productService = new ProductService($pdo);
    $userService = new UserService($pdo, $publicUrls, $loginAttempts);
    $lockerService = new LockerService($pdo);
    $compartmentService = new CompartmentService($pdo);

    return [
        'pdo' => $pdo,
        'logger' => $logger,
        'authService' => $authService,
        'handlers' => [
            'listAuthClinics' => new ListAuthClinicsHandler($authService),
            'clinicLogin' => new ClinicLoginHandler(new ClinicLoginValidator(), $authService),
            'clinicLogout' => new ClinicLogoutHandler($authService),
            'listAuthStaff' => new ListAuthStaffHandler($authService),
            'pinLogin' => new PinLoginHandler(new PinLoginValidator(), $authService),
            'login' => new LoginHandler(new LoginValidator(), $authService),
            'logout' => new LogoutHandler($authService),
            'requestRecoveryClinic' => new RequestRecoveryClinicHandler(new RecoveryValidator(), $recoveryService),
            'requestRecoveryUser' => new RequestRecoveryUserHandler(new RecoveryValidator(), $recoveryService),
            'confirmRecovery' => new ConfirmRecoveryHandler(new RecoveryValidator(), $recoveryService),
            'listInventory' => new ListInventoryHandler($inventoryService),
            'patchInventoryProduct' => new PatchInventoryProductHandler($inventoryValidator, $inventoryService),
            'createEntryLog' => new CreateEntryLogHandler($entryLogValidator, $entryLogService),
            'listEntryLogs' => new ListEntryLogsHandler($entryLogService),
            'createExitLog' => new CreateExitLogHandler($exitLogValidator, $exitLogService),
            'listExitLogs' => new ListExitLogsHandler($exitLogService),
            'getExitLog' => new GetExitLogHandler($exitLogService),
            'patchExitLogItems' => new PatchExitLogItemsHandler($exitLogValidator, $exitLogService),
            'confirmExitLog' => new ConfirmExitLogHandler($exitLogService),
            'cancelExitLog' => new CancelExitLogHandler($exitLogService),
            'openExitLogLock' => new OpenExitLogLockHandler($openExitLogLockAction),
            'createIncident' => new CreateIncidentHandler(new IncidentValidator(), $incidentService),
            'listIncidents' => new ListIncidentsHandler($incidentService),
            'upsertSetting' => new UpsertSettingHandler(new SettingValidator(), $settingService),
            'listSettings' => new ListSettingsHandler($settingService),
            'getClinic' => new GetClinicHandler($clinicService),
            'patchClinic' => new PatchClinicHandler(new ClinicValidator(), $clinicService),
            'uploadClinicImage' => new UploadClinicImageHandler($clinicService, $imageStorage),
            'deleteClinicImage' => new DeleteClinicImageHandler($clinicService, $imageStorage),
            'requestClinicRecovery' => new RequestClinicRecoveryHandler(new RecoveryValidator(), $recoveryService),
            'patchClinicSettings' => new PatchClinicSettingsHandler(new ClinicSettingsValidator(), $settingService),
            'listProducts' => new ListProductsHandler($productService),
            'getProduct' => new GetProductHandler($productService),
            'getProductStockLocations' => new GetProductStockLocationsHandler($inventoryService),
            'createProduct' => new CreateProductHandler(new ProductValidator(), $productService),
            'patchProduct' => new PatchProductHandler(new ProductValidator(), $productService),
            'deleteProduct' => new DeleteProductHandler($productService),
            'listUsers' => new ListUsersHandler($userService),
            'getUser' => new GetUserHandler($userService),
            'createUser' => new CreateUserHandler(new UserValidator(), $userService),
            'patchUser' => new PatchUserHandler(new UserValidator(), $userService),
            'deleteUser' => new DeleteUserHandler($userService),
            'sendUserRecovery' => new SendUserRecoveryHandler(new UserValidator(), $recoveryService),
            'uploadUserImage' => new UploadUserImageHandler($userService, $imageStorage),
            'deleteUserImage' => new DeleteUserImageHandler($userService, $imageStorage),
            'listLockers' => new ListLockersHandler($lockerService),
            'listLockersWithCompartments' => new ListLockersWithCompartmentsHandler($lockerService),
            'getLocker' => new GetLockerHandler($lockerService),
            'createLocker' => new CreateLockerHandler(new LockerValidator(), $lockerService),
            'patchLocker' => new PatchLockerHandler(new LockerValidator(), $lockerService),
            'deleteLocker' => new DeleteLockerHandler($lockerService),
            'listCompartments' => new ListCompartmentsHandler($compartmentService),
            'getCompartment' => new GetCompartmentHandler($compartmentService),
            'createCompartment' => new CreateCompartmentHandler(new CompartmentValidator(), $compartmentService),
            'patchCompartment' => new PatchCompartmentHandler(new CompartmentValidator(), $compartmentService),
            'deleteCompartment' => new DeleteCompartmentHandler($compartmentService),
            'openApi' => new OpenApiController(),
        ],
    ];
};
