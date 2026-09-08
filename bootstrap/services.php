<?php

declare(strict_types=1);

use App\Application\Audit\AuditActivitySanitizer;
use App\Application\Auth\ClinicAccessService;
use App\Application\Auth\RequestClinicResolver;
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
use App\Modules\Audit\Handlers\GetAuditActivityHandler;
use App\Modules\Audit\Handlers\GetAuditLogHandler;
use App\Modules\Audit\Handlers\ListAuditActivityHandler;
use App\Modules\Audit\Handlers\ListAuditLogsHandler;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Audit\Services\AuditLogService;
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
use App\Modules\Clinic\Handlers\CreateClinicHandler;
use App\Modules\Clinic\Handlers\DeleteClinicImageHandler;
use App\Modules\Clinic\Handlers\GetClinicHandler;
use App\Modules\Clinic\Handlers\ListClinicsHandler;
use App\Modules\Clinic\Handlers\PatchClinicByIdHandler;
use App\Modules\Clinic\Handlers\PatchClinicSettingsHandler;
use App\Modules\Clinic\Handlers\RequestClinicRecoveryHandler;
use App\Modules\Clinic\Handlers\UploadClinicImageHandler;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Clinic\Validators\ClinicSettingsValidator;
use App\Modules\Clinic\Validators\ClinicValidator;
use App\Modules\Zones\Handlers\CreateZoneHandler;
use App\Modules\Zones\Handlers\DeleteZoneHandler;
use App\Modules\Zones\Handlers\GetZoneHandler;
use App\Modules\Zones\Handlers\ListZonesHandler;
use App\Modules\Zones\Handlers\PatchZoneHandler;
use App\Modules\Zones\Services\ZoneService;
use App\Modules\Zones\Validators\ZoneValidator;
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
use App\Modules\Incidents\Handlers\PatchIncidentHandler;
use App\Modules\Incidents\Services\IncidentService;
use App\Modules\Incidents\Validators\IncidentValidator;
use App\Modules\Inventory\Handlers\ListInventoryHandler;
use App\Modules\Inventory\Handlers\PatchInventoryProductHandler;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Validators\InventoryValidator;
use App\Modules\Ambientes\Handlers\AssociateClinicAmbienteHandler;
use App\Modules\Ambientes\Handlers\CreateAmbienteHandler;
use App\Modules\Ambientes\Handlers\DeleteAmbienteHandler;
use App\Modules\Ambientes\Handlers\DisassociateClinicAmbienteHandler;
use App\Modules\Ambientes\Handlers\GetAmbienteHandler;
use App\Modules\Ambientes\Handlers\ListAmbientesHandler;
use App\Modules\Ambientes\Handlers\ListAmbientesWithZonesHandler;
use App\Modules\Ambientes\Handlers\PatchAmbienteHandler;
use App\Modules\Ambientes\Handlers\PatchClinicAmbienteVisibilityHandler;
use App\Modules\Ambientes\Services\AmbienteService;
use App\Modules\Ambientes\Validators\AmbienteValidator;
use App\Modules\Brands\Handlers\AttachBrandSupplierHandler;
use App\Modules\Brands\Handlers\CreateBrandHandler;
use App\Modules\Brands\Handlers\DeleteBrandHandler;
use App\Modules\Brands\Handlers\DetachBrandSupplierHandler;
use App\Modules\Brands\Handlers\GetBrandHandler;
use App\Modules\Brands\Handlers\ListBrandSuppliersHandler;
use App\Modules\Brands\Handlers\ListBrandsHandler;
use App\Modules\Brands\Handlers\PatchBrandHandler;
use App\Modules\Brands\Services\BrandService;
use App\Modules\Brands\Validators\BrandValidator;
use App\Modules\Categories\Handlers\CreateCategoryHandler;
use App\Modules\Categories\Handlers\DeleteCategoryHandler;
use App\Modules\Categories\Handlers\GetCategoryHandler;
use App\Modules\Categories\Handlers\ListCategoriesHandler;
use App\Modules\Categories\Handlers\PatchCategoryHandler;
use App\Modules\Categories\Services\CategoryService;
use App\Modules\Categories\Validators\CategoryValidator;
use App\Modules\DispensingTypes\Handlers\AttachDispensingTypeRoleHandler;
use App\Modules\DispensingTypes\Handlers\CreateDispensingTypeHandler;
use App\Modules\DispensingTypes\Handlers\DeleteDispensingTypeHandler;
use App\Modules\DispensingTypes\Handlers\DetachDispensingTypeRoleHandler;
use App\Modules\DispensingTypes\Handlers\GetDispensingTypeHandler;
use App\Modules\DispensingTypes\Handlers\ListDispensingTypeRolesHandler;
use App\Modules\DispensingTypes\Handlers\ListDispensingTypesHandler;
use App\Modules\DispensingTypes\Handlers\PatchDispensingTypeHandler;
use App\Modules\DispensingTypes\Services\DispensingTypeService;
use App\Modules\DispensingTypes\Validators\DispensingTypeValidator;
use App\Modules\Products\Handlers\CreateProductHandler;
use App\Modules\Products\Handlers\CreateProductSupplierHandler;
use App\Modules\Products\Handlers\DeleteProductHandler;
use App\Modules\Products\Handlers\DeleteProductSupplierHandler;
use App\Modules\Products\Handlers\GetProductHandler;
use App\Modules\Products\Handlers\GetProductStockLocationsHandler;
use App\Modules\Products\Handlers\ListProductSuppliersHandler;
use App\Modules\Products\Handlers\ListProductsHandler;
use App\Modules\Products\Handlers\PatchClinicProductVisibilityHandler;
use App\Modules\Products\Handlers\PatchProductHandler;
use App\Modules\Products\Handlers\PatchProductSupplierHandler;
use App\Modules\Products\Handlers\SetPreferredProductSupplierHandler;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Validators\ProductValidator;
use App\Modules\ProductImports\Handlers\CancelProductImportHandler;
use App\Modules\ProductImports\Handlers\ConfirmProductImportHandler;
use App\Modules\ProductImports\Handlers\CreateProductImportHandler;
use App\Modules\ProductImports\Handlers\GetProductImportHandler;
use App\Modules\ProductImports\Handlers\ListProductImportRowsHandler;
use App\Modules\ProductImports\Handlers\ListProductImportsHandler;
use App\Modules\ProductImports\Handlers\PatchProductImportRowHandler;
use App\Modules\ProductImports\Handlers\PatchProductImportRowsHandler;
use App\Modules\ProductImports\Services\ProductImportService;
use App\Modules\ProductTags\Handlers\CreateProductTagHandler;
use App\Modules\ProductTags\Handlers\DeleteProductTagHandler;
use App\Modules\ProductTags\Handlers\GetProductTagHandler;
use App\Modules\ProductTags\Handlers\ListProductTagsHandler;
use App\Modules\ProductTags\Handlers\PatchProductTagHandler;
use App\Modules\ProductTags\Services\ProductTagService;
use App\Modules\ProductTags\Validators\ProductTagValidator;
use App\Modules\Roles\Handlers\CreateRoleHandler;
use App\Modules\Roles\Handlers\DeleteRoleHandler;
use App\Modules\Roles\Handlers\GetRoleHandler;
use App\Modules\Roles\Handlers\ListRolesHandler;
use App\Modules\Roles\Handlers\PatchRoleHandler;
use App\Modules\Roles\Services\RoleService;
use App\Modules\Roles\Validators\RoleValidator;
use App\Modules\SubBrands\Handlers\CreateSubBrandHandler;
use App\Modules\SubBrands\Handlers\DeleteSubBrandHandler;
use App\Modules\SubBrands\Handlers\GetSubBrandHandler;
use App\Modules\SubBrands\Handlers\ListSubBrandsHandler;
use App\Modules\SubBrands\Handlers\PatchSubBrandHandler;
use App\Modules\SubBrands\Services\SubBrandService;
use App\Modules\SubBrands\Validators\SubBrandValidator;
use App\Modules\Subcategories\Handlers\CreateSubcategoryHandler;
use App\Modules\Subcategories\Handlers\DeleteSubcategoryHandler;
use App\Modules\Subcategories\Handlers\GetSubcategoryHandler;
use App\Modules\Subcategories\Handlers\ListSubcategoriesHandler;
use App\Modules\Subcategories\Handlers\PatchSubcategoryHandler;
use App\Modules\Subcategories\Services\SubcategoryService;
use App\Modules\Subcategories\Validators\SubcategoryValidator;
use App\Modules\Specialties\Handlers\CreateSpecialtyHandler;
use App\Modules\Specialties\Handlers\DeleteSpecialtyHandler;
use App\Modules\Specialties\Handlers\GetSpecialtyHandler;
use App\Modules\Specialties\Handlers\ListSpecialtiesHandler;
use App\Modules\Specialties\Handlers\PatchSpecialtyHandler;
use App\Modules\Specialties\Services\SpecialtyService;
use App\Modules\Specialties\Validators\SpecialtyValidator;
use App\Modules\Species\Handlers\CreateSpeciesHandler;
use App\Modules\Species\Handlers\DeleteSpeciesHandler;
use App\Modules\Species\Handlers\GetSpeciesHandler;
use App\Modules\Species\Handlers\ListSpeciesHandler;
use App\Modules\Species\Handlers\PatchSpeciesHandler;
use App\Modules\Species\Services\SpeciesService;
use App\Modules\Species\Validators\SpeciesValidator;
use App\Modules\Suppliers\Handlers\CreateSupplierHandler;
use App\Modules\Suppliers\Handlers\DeleteSupplierHandler;
use App\Modules\Suppliers\Handlers\GetSupplierHandler;
use App\Modules\Suppliers\Handlers\ListSuppliersHandler;
use App\Modules\Suppliers\Handlers\PatchSupplierHandler;
use App\Modules\Suppliers\Services\SupplierService;
use App\Modules\Suppliers\Validators\SupplierValidator;
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
    $auditLogService = new AuditLogService($pdo);
    $auditActivitySanitizer = new AuditActivitySanitizer();
    $auditActivityService = new AuditActivityService($pdo, $auditActivitySanitizer);

    $authService = new AuthService($pdo, $tokenService, $loginAttempts, $authMapper, $auditLogService);

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

    $inventoryService = new InventoryService($pdo, $auditActivityService);
    $inventoryValidator = new InventoryValidator($locationValidator);

    $entryLogService = new EntryLogService($pdo, $locationValidator, $auditActivityService);
    $entryLogValidator = new EntryLogValidator($locationValidator);

    $exitLogService = new ExitLogService($pdo, $locationValidator, $auditActivityService);
    $exitLogValidator = new ExitLogValidator($locationValidator);
    $exitLogLockPort = new PdoExitLogLockPort($pdo);
    $mqttHost = $appConfig->mqttHost();
    $lockCommandPublisher = ($mqttHost !== '' && !$appConfig->mqttDisabled())
        ? new PhpMqttLockCommandPublisher($config, $logger)
        : new NoOpLockCommandPublisher();
    $openExitLogLockAction = new OpenExitLogLockAction(
        $exitLogLockPort,
        $lockCommandPublisher,
        $logger,
        $exitLogService,
        $auditActivityService,
    );

    $incidentService = new IncidentService($pdo, $auditActivityService);
    $settingService = new SettingService($pdo, $auditActivityService);
    $clinicService = new ClinicService($pdo, $publicUrls, $auditActivityService);
    $productService = new ProductService($pdo, $auditActivityService);
    $productValidator = new ProductValidator();
    $categoryService = new CategoryService($pdo, $auditActivityService);
    $categoryValidator = new CategoryValidator();
    $subcategoryService = new SubcategoryService($pdo, $auditActivityService);
    $subcategoryValidator = new SubcategoryValidator();
    $brandService = new BrandService($pdo, $auditActivityService);
    $brandValidator = new BrandValidator();
    $subBrandService = new SubBrandService($pdo, $auditActivityService);
    $subBrandValidator = new SubBrandValidator();
    $speciesService = new SpeciesService($pdo, $auditActivityService);
    $speciesValidator = new SpeciesValidator();
    $specialtyService = new SpecialtyService($pdo, $auditActivityService);
    $specialtyValidator = new SpecialtyValidator();
    $productTagService = new ProductTagService($pdo, $auditActivityService);
    $productTagValidator = new ProductTagValidator();
    $productImportService = new ProductImportService($pdo);
    $supplierService = new SupplierService($pdo, $auditActivityService);
    $supplierValidator = new SupplierValidator();
    $dispensingTypeService = new DispensingTypeService($pdo, $auditActivityService);
    $dispensingTypeValidator = new DispensingTypeValidator();
    $roleService = new RoleService($pdo, $auditActivityService);
    $roleValidator = new RoleValidator();
    $userService = new UserService($pdo, $publicUrls, $loginAttempts, $auditActivityService);
    $ambienteService = new AmbienteService($pdo, $auditActivityService);
    $zoneService = new ZoneService($pdo, $auditActivityService);
    $clinicAccess = new ClinicAccessService($pdo);
    $clinicResolver = new RequestClinicResolver($clinicAccess);

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
            'listIncidents' => new ListIncidentsHandler($clinicAccess, $clinicResolver, $incidentService),
            'patchIncident' => new PatchIncidentHandler($clinicAccess, new IncidentValidator(), $incidentService),
            'upsertSetting' => new UpsertSettingHandler(new SettingValidator(), $settingService),
            'listSettings' => new ListSettingsHandler($settingService),
            'getClinic' => new GetClinicHandler($clinicResolver, $clinicService),
            'listClinics' => new ListClinicsHandler($clinicAccess, $clinicService),
            'createClinic' => new CreateClinicHandler($clinicAccess, new ClinicValidator(), $clinicService),
            'patchClinicById' => new PatchClinicByIdHandler($clinicAccess, new ClinicValidator(), $clinicService),
            'uploadClinicImage' => new UploadClinicImageHandler($clinicService, $imageStorage),
            'deleteClinicImage' => new DeleteClinicImageHandler($clinicService, $imageStorage),
            'requestClinicRecovery' => new RequestClinicRecoveryHandler(new RecoveryValidator(), $recoveryService),
            'patchClinicSettings' => new PatchClinicSettingsHandler(new ClinicSettingsValidator(), $settingService),
            'patchClinicProductVisibility' => new PatchClinicProductVisibilityHandler($clinicResolver, $productService),
            'patchClinicAmbienteVisibility' => new PatchClinicAmbienteVisibilityHandler($clinicResolver, $ambienteService),
            'associateClinicAmbiente' => new AssociateClinicAmbienteHandler($clinicAccess, $ambienteService),
            'disassociateClinicAmbiente' => new DisassociateClinicAmbienteHandler($clinicAccess, $ambienteService),
            'listProducts' => new ListProductsHandler($clinicAccess, $clinicResolver, $productService),
            'getProduct' => new GetProductHandler($clinicAccess, $clinicResolver, $productService),
            'getProductStockLocations' => new GetProductStockLocationsHandler($inventoryService),
            'listProductSuppliers' => new ListProductSuppliersHandler($productService),
            'createProductSupplier' => new CreateProductSupplierHandler($clinicAccess, $productValidator, $productService),
            'patchProductSupplier' => new PatchProductSupplierHandler($clinicAccess, $productValidator, $productService),
            'deleteProductSupplier' => new DeleteProductSupplierHandler($clinicAccess, $productService),
            'setPreferredProductSupplier' => new SetPreferredProductSupplierHandler($clinicAccess, $productService),
            'createProduct' => new CreateProductHandler($clinicAccess, $productValidator, $productService),
            'patchProduct' => new PatchProductHandler($clinicAccess, $productValidator, $productService),
            'deleteProduct' => new DeleteProductHandler($clinicAccess, $productService),
            'listCategories' => new ListCategoriesHandler($categoryService),
            'getCategory' => new GetCategoryHandler($categoryService),
            'createCategory' => new CreateCategoryHandler($clinicAccess, $categoryValidator, $categoryService),
            'patchCategory' => new PatchCategoryHandler($clinicAccess, $categoryValidator, $categoryService),
            'deleteCategory' => new DeleteCategoryHandler($clinicAccess, $categoryService),
            'listSubcategories' => new ListSubcategoriesHandler($subcategoryService),
            'getSubcategory' => new GetSubcategoryHandler($subcategoryService),
            'createSubcategory' => new CreateSubcategoryHandler($clinicAccess, $subcategoryValidator, $subcategoryService),
            'patchSubcategory' => new PatchSubcategoryHandler($clinicAccess, $subcategoryValidator, $subcategoryService),
            'deleteSubcategory' => new DeleteSubcategoryHandler($clinicAccess, $subcategoryService),
            'listBrands' => new ListBrandsHandler($brandService),
            'getBrand' => new GetBrandHandler($brandService),
            'createBrand' => new CreateBrandHandler($clinicAccess, $brandValidator, $brandService),
            'patchBrand' => new PatchBrandHandler($clinicAccess, $brandValidator, $brandService),
            'deleteBrand' => new DeleteBrandHandler($clinicAccess, $brandService),
            'listBrandSuppliers' => new ListBrandSuppliersHandler($brandService),
            'attachBrandSupplier' => new AttachBrandSupplierHandler($clinicAccess, $brandService),
            'detachBrandSupplier' => new DetachBrandSupplierHandler($clinicAccess, $brandService),
            'listSubBrands' => new ListSubBrandsHandler($subBrandService),
            'getSubBrand' => new GetSubBrandHandler($subBrandService),
            'createSubBrand' => new CreateSubBrandHandler($clinicAccess, $subBrandValidator, $subBrandService),
            'patchSubBrand' => new PatchSubBrandHandler($clinicAccess, $subBrandValidator, $subBrandService),
            'deleteSubBrand' => new DeleteSubBrandHandler($clinicAccess, $subBrandService),
            'listSpecies' => new ListSpeciesHandler($speciesService),
            'getSpecies' => new GetSpeciesHandler($speciesService),
            'createSpecies' => new CreateSpeciesHandler($clinicAccess, $speciesValidator, $speciesService),
            'patchSpecies' => new PatchSpeciesHandler($clinicAccess, $speciesValidator, $speciesService),
            'deleteSpecies' => new DeleteSpeciesHandler($clinicAccess, $speciesService),
            'listSpecialties' => new ListSpecialtiesHandler($specialtyService),
            'getSpecialty' => new GetSpecialtyHandler($specialtyService),
            'createSpecialty' => new CreateSpecialtyHandler($clinicAccess, $specialtyValidator, $specialtyService),
            'patchSpecialty' => new PatchSpecialtyHandler($clinicAccess, $specialtyValidator, $specialtyService),
            'deleteSpecialty' => new DeleteSpecialtyHandler($clinicAccess, $specialtyService),
            'listProductTags' => new ListProductTagsHandler($productTagService),
            'getProductTag' => new GetProductTagHandler($productTagService),
            'createProductTag' => new CreateProductTagHandler($clinicAccess, $productTagValidator, $productTagService),
            'patchProductTag' => new PatchProductTagHandler($clinicAccess, $productTagValidator, $productTagService),
            'deleteProductTag' => new DeleteProductTagHandler($clinicAccess, $productTagService),
            'listProductImports' => new ListProductImportsHandler($clinicAccess, $productImportService),
            'createProductImport' => new CreateProductImportHandler($clinicAccess, $productImportService),
            'getProductImport' => new GetProductImportHandler($clinicAccess, $productImportService),
            'listProductImportRows' => new ListProductImportRowsHandler($clinicAccess, $productImportService),
            'patchProductImportRow' => new PatchProductImportRowHandler($clinicAccess, $productImportService),
            'patchProductImportRows' => new PatchProductImportRowsHandler($clinicAccess, $productImportService),
            'confirmProductImport' => new ConfirmProductImportHandler($clinicAccess, $productImportService),
            'cancelProductImport' => new CancelProductImportHandler($clinicAccess, $productImportService),
            'listSuppliers' => new ListSuppliersHandler($supplierService),
            'getSupplier' => new GetSupplierHandler($supplierService),
            'createSupplier' => new CreateSupplierHandler($clinicAccess, $supplierValidator, $supplierService),
            'patchSupplier' => new PatchSupplierHandler($clinicAccess, $supplierValidator, $supplierService),
            'deleteSupplier' => new DeleteSupplierHandler($clinicAccess, $supplierService),
            'listDispensingTypes' => new ListDispensingTypesHandler($dispensingTypeService),
            'getDispensingType' => new GetDispensingTypeHandler($dispensingTypeService),
            'createDispensingType' => new CreateDispensingTypeHandler($clinicAccess, $dispensingTypeValidator, $dispensingTypeService),
            'patchDispensingType' => new PatchDispensingTypeHandler($clinicAccess, $dispensingTypeValidator, $dispensingTypeService),
            'deleteDispensingType' => new DeleteDispensingTypeHandler($clinicAccess, $dispensingTypeService),
            'listDispensingTypeRoles' => new ListDispensingTypeRolesHandler($dispensingTypeService),
            'attachDispensingTypeRole' => new AttachDispensingTypeRoleHandler($clinicAccess, $dispensingTypeService),
            'detachDispensingTypeRole' => new DetachDispensingTypeRoleHandler($clinicAccess, $dispensingTypeService),
            'listRoles' => new ListRolesHandler($roleService),
            'getRole' => new GetRoleHandler($roleService),
            'createRole' => new CreateRoleHandler($clinicAccess, $roleValidator, $roleService),
            'patchRole' => new PatchRoleHandler($clinicAccess, $roleValidator, $roleService),
            'deleteRole' => new DeleteRoleHandler($clinicAccess, $roleService),
            'listUsers' => new ListUsersHandler($clinicAccess, $userService),
            'getUser' => new GetUserHandler($clinicAccess, $userService),
            'createUser' => new CreateUserHandler($clinicAccess, new UserValidator(), $userService),
            'patchUser' => new PatchUserHandler($clinicAccess, new UserValidator(), $userService),
            'deleteUser' => new DeleteUserHandler($clinicAccess, $userService),
            'sendUserRecovery' => new SendUserRecoveryHandler(new UserValidator(), $recoveryService),
            'uploadUserImage' => new UploadUserImageHandler($userService, $imageStorage),
            'deleteUserImage' => new DeleteUserImageHandler($userService, $imageStorage),
            'listAmbientes' => new ListAmbientesHandler($clinicAccess, $clinicResolver, $ambienteService),
            'listAmbientesWithZones' => new ListAmbientesWithZonesHandler($clinicAccess, $clinicResolver, $ambienteService),
            'getAmbiente' => new GetAmbienteHandler($clinicAccess, $clinicResolver, $ambienteService),
            'createAmbiente' => new CreateAmbienteHandler($clinicAccess, new AmbienteValidator(), $ambienteService),
            'patchAmbiente' => new PatchAmbienteHandler($clinicAccess, new AmbienteValidator(), $ambienteService),
            'deleteAmbiente' => new DeleteAmbienteHandler($clinicAccess, $ambienteService),
            'listZones' => new ListZonesHandler($clinicAccess, $clinicResolver, $zoneService),
            'getZone' => new GetZoneHandler($clinicAccess, $clinicResolver, $zoneService),
            'createZone' => new CreateZoneHandler($clinicAccess, new ZoneValidator(), $zoneService),
            'patchZone' => new PatchZoneHandler($clinicAccess, new ZoneValidator(), $zoneService),
            'deleteZone' => new DeleteZoneHandler($clinicAccess, $zoneService),
            'listAuditLogs' => new ListAuditLogsHandler($clinicAccess, $auditLogService),
            'getAuditLog' => new GetAuditLogHandler($clinicAccess, $auditLogService),
            'listAuditActivity' => new ListAuditActivityHandler($clinicAccess, $auditActivityService),
            'getAuditActivity' => new GetAuditActivityHandler($clinicAccess, $auditActivityService),
            'openApi' => new OpenApiController(),
        ],
    ];
};
