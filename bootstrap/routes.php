<?php

declare(strict_types=1);

use App\Application\Http\ApiResponse;
use App\Infrastructure\Http\Router;
use App\Infrastructure\OpenAPI\OpenApiController;

/**
 * @param array<string, callable|OpenApiController> $handlers
 * @return array<string, list<string>>
 */
return static function (Router $router, array $handlers): array {
    /** @var OpenApiController $openApi */
    $openApi = $handlers['openApi'];

    $router->addRoute('GET', '/up', fn ($request) => ApiResponse::success($request, ['status' => 'up']));
    $router->addRoute('GET', '/api/v1/auth/clinics', fn ($request) => $handlers['listAuthClinics']($request));
    $router->addRoute('POST', '/api/v1/auth/clinic/login', fn ($request) => $handlers['clinicLogin']($request));
    $router->addRoute('POST', '/api/v1/auth/clinic/logout', fn ($request) => $handlers['clinicLogout']($request));
    $router->addRoute('GET', '/api/v1/auth/staff', fn ($request) => $handlers['listAuthStaff']($request));
    $router->addRoute('POST', '/api/v1/auth/login/pin', fn ($request) => $handlers['pinLogin']($request));
    $router->addRoute('POST', '/api/v1/auth/login', fn ($request) => $handlers['login']($request));
    $router->addRoute('POST', '/api/v1/auth/logout', fn ($request) => $handlers['logout']($request));
    $router->addRoute('POST', '/api/v1/auth/recovery/clinic', fn ($request) => $handlers['requestRecoveryClinic']($request));
    $router->addRoute('POST', '/api/v1/auth/recovery/user', fn ($request) => $handlers['requestRecoveryUser']($request));
    $router->addRoute('POST', '/api/v1/auth/recovery/confirm', fn ($request) => $handlers['confirmRecovery']($request));
    $router->addRoute('GET', '/api/v1/me', function ($request) {
        $user = (array) $request->getAttribute('user', []);

        return ApiResponse::success($request, [
            'id' => (string) ($user['user_id'] ?? $user['id'] ?? ''),
            'clinic_id' => (string) ($user['clinic_id'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ]);
    });
    $router->addRoute('GET', '/api/v1/clinic', fn ($request) => $handlers['getClinic']($request));
    $router->addRoute('POST', '/api/v1/clinic/image', fn ($request) => $handlers['uploadClinicImage']($request));
    $router->addRoute('DELETE', '/api/v1/clinic/image', fn ($request) => $handlers['deleteClinicImage']($request));
    $router->addRoute('POST', '/api/v1/clinic/recovery', fn ($request) => $handlers['requestClinicRecovery']($request));
    $router->addRoute('PATCH', '/api/v1/clinic/settings', fn ($request) => $handlers['patchClinicSettings']($request));
    $router->addRoute('PATCH', '/api/v1/clinic/products/{product_id}', fn ($request) => $handlers['patchClinicProductVisibility']($request));
    $router->addRoute('PATCH', '/api/v1/clinic/ambientes/{ambiente_id}', fn ($request) => $handlers['patchClinicAmbienteVisibility']($request));
    $router->addRoute('PATCH', '/api/v1/clinics/{clinic_id}/products/{product_id}', fn ($request) => $handlers['patchClinicProductVisibility']($request));
    $router->addRoute('PATCH', '/api/v1/clinics/{clinic_id}/ambientes/{ambiente_id}', fn ($request) => $handlers['patchClinicAmbienteVisibility']($request));
    $router->addRoute('GET', '/api/v1/clinics', fn ($request) => $handlers['listClinics']($request));
    $router->addRoute('POST', '/api/v1/clinics', fn ($request) => $handlers['createClinic']($request));
    $router->addRoute('PATCH', '/api/v1/clinics/{clinic_id}', fn ($request) => $handlers['patchClinicById']($request));
    $router->addRoute('POST', '/api/v1/clinics/{clinic_id}/ambientes', fn ($request) => $handlers['associateClinicAmbiente']($request));
    $router->addRoute('DELETE', '/api/v1/clinics/{clinic_id}/ambientes/{ambiente_id}', fn ($request) => $handlers['disassociateClinicAmbiente']($request));
    $router->addRoute('GET', '/api/v1/products', fn ($request) => $handlers['listProducts']($request));
    $router->addRoute('GET', '/api/v1/products/{product_id}/stock-locations', fn ($request) => $handlers['getProductStockLocations']($request));
    $router->addRoute('GET', '/api/v1/products/{product_id}', fn ($request) => $handlers['getProduct']($request));
    $router->addRoute('POST', '/api/v1/products', fn ($request) => $handlers['createProduct']($request));
    $router->addRoute('PATCH', '/api/v1/products/{product_id}', fn ($request) => $handlers['patchProduct']($request));
    $router->addRoute('DELETE', '/api/v1/products/{product_id}', fn ($request) => $handlers['deleteProduct']($request));
    $router->addRoute('GET', '/api/v1/users', fn ($request) => $handlers['listUsers']($request));
    $router->addRoute('GET', '/api/v1/users/{user_id}', fn ($request) => $handlers['getUser']($request));
    $router->addRoute('POST', '/api/v1/users', fn ($request) => $handlers['createUser']($request));
    $router->addRoute('PATCH', '/api/v1/users/{user_id}', fn ($request) => $handlers['patchUser']($request));
    $router->addRoute('POST', '/api/v1/users/{user_id}/recovery', fn ($request) => $handlers['sendUserRecovery']($request));
    $router->addRoute('POST', '/api/v1/users/{user_id}/image', fn ($request) => $handlers['uploadUserImage']($request));
    $router->addRoute('DELETE', '/api/v1/users/{user_id}/image', fn ($request) => $handlers['deleteUserImage']($request));
    $router->addRoute('DELETE', '/api/v1/users/{user_id}', fn ($request) => $handlers['deleteUser']($request));
    $router->addRoute('GET', '/api/v1/ambientes', fn ($request) => $handlers['listAmbientes']($request));
    $router->addRoute('GET', '/api/v1/ambientes/tree', fn ($request) => $handlers['listAmbientesWithZones']($request));
    $router->addRoute('GET', '/api/v1/ambientes/{ambiente_id}', fn ($request) => $handlers['getAmbiente']($request));
    $router->addRoute('POST', '/api/v1/ambientes', fn ($request) => $handlers['createAmbiente']($request));
    $router->addRoute('PATCH', '/api/v1/ambientes/{ambiente_id}', fn ($request) => $handlers['patchAmbiente']($request));
    $router->addRoute('DELETE', '/api/v1/ambientes/{ambiente_id}', fn ($request) => $handlers['deleteAmbiente']($request));
    $router->addRoute('GET', '/api/v1/zones', fn ($request) => $handlers['listZones']($request));
    $router->addRoute('GET', '/api/v1/zones/{zone_id}', fn ($request) => $handlers['getZone']($request));
    $router->addRoute('POST', '/api/v1/zones', fn ($request) => $handlers['createZone']($request));
    $router->addRoute('PATCH', '/api/v1/zones/{zone_id}', fn ($request) => $handlers['patchZone']($request));
    $router->addRoute('DELETE', '/api/v1/zones/{zone_id}', fn ($request) => $handlers['deleteZone']($request));
    $router->addRoute('GET', '/api/v1/inventory', fn ($request) => $handlers['listInventory']($request));
    $router->addRoute('PATCH', '/api/v1/inventory/products/{product_id}', fn ($request) => $handlers['patchInventoryProduct']($request));
    $router->addRoute('GET', '/api/v1/entry-logs', fn ($request) => $handlers['listEntryLogs']($request));
    $router->addRoute('POST', '/api/v1/entry-logs', fn ($request) => $handlers['createEntryLog']($request));
    $router->addRoute('GET', '/api/v1/exit-logs', fn ($request) => $handlers['listExitLogs']($request));
    $router->addRoute('POST', '/api/v1/exit-logs', fn ($request) => $handlers['createExitLog']($request));
    $router->addRoute('GET', '/api/v1/exit-logs/{id}', fn ($request) => $handlers['getExitLog']($request));
    $router->addRoute('PATCH', '/api/v1/exit-logs/{id}', fn ($request) => $handlers['patchExitLogItems']($request));
    $router->addRoute('POST', '/api/v1/exit-logs/{id}/confirm', fn ($request) => $handlers['confirmExitLog']($request));
    $router->addRoute('POST', '/api/v1/exit-logs/{id}/cancel', fn ($request) => $handlers['cancelExitLog']($request));
    $router->addRoute('POST', '/api/v1/exit-logs/{id}/open-lock', fn ($request) => $handlers['openExitLogLock']($request));
    $router->addRoute('GET', '/api/v1/incidents', fn ($request) => $handlers['listIncidents']($request));
    $router->addRoute('POST', '/api/v1/incidents', fn ($request) => $handlers['createIncident']($request));
    $router->addRoute('PATCH', '/api/v1/incidents/{incident_id}', fn ($request) => $handlers['patchIncident']($request));
    $router->addRoute('GET', '/api/v1/settings', fn ($request) => $handlers['listSettings']($request));
    $router->addRoute('POST', '/api/v1/settings', fn ($request) => $handlers['upsertSetting']($request));
    $router->addRoute('GET', '/docs', fn () => $openApi->docsYaml());
    $router->addRoute('GET', '/docs/ui', fn () => $openApi->docsUi());

    return [
        'GET /api/v1/me' => ['STAFF'],
        'POST /api/v1/auth/logout' => ['STAFF'],
        'GET /api/v1/clinic' => ['STAFF'],
        'POST /api/v1/clinic/image' => ['ADMIN'],
        'DELETE /api/v1/clinic/image' => ['ADMIN'],
        'POST /api/v1/clinic/recovery' => ['ADMIN'],
        'PATCH /api/v1/clinic/settings' => ['ADMIN'],
        're:/^PATCH \\/api\\/v1\\/clinic\\/products\\/[^\\/]+$/' => ['ADMIN'],
        're:/^PATCH \\/api\\/v1\\/clinic\\/ambientes\\/[^\\/]+$/' => ['ADMIN'],
        're:/^PATCH \\/api\\/v1\\/clinics\\/[^\\/]+\\/products\\/[^\\/]+$/' => ['ADMIN'],
        're:/^PATCH \\/api\\/v1\\/clinics\\/[^\\/]+\\/ambientes\\/[^\\/]+$/' => ['ADMIN'],
        'GET /api/v1/clinics' => ['SUPER_ADMIN'],
        'POST /api/v1/clinics' => ['SUPER_ADMIN'],
        're:/^PATCH \\/api\\/v1\\/clinics\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        're:/^POST \\/api\\/v1\\/clinics\\/[^\\/]+\\/ambientes$/' => ['SUPER_ADMIN'],
        're:/^DELETE \\/api\\/v1\\/clinics\\/[^\\/]+\\/ambientes\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        'GET /api/v1/products' => ['STAFF'],
        're:/^GET \\/api\\/v1\\/products\\/[^\\/]+\\/stock-locations$/' => ['STAFF'],
        're:/^GET \\/api\\/v1\\/products\\/[^\\/]+$/' => ['STAFF'],
        'POST /api/v1/products' => ['SUPER_ADMIN'],
        're:/^PATCH \\/api\\/v1\\/products\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        're:/^DELETE \\/api\\/v1\\/products\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        'GET /api/v1/users' => ['SUPER_ADMIN'],
        're:/^GET \\/api\\/v1\\/users\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        'POST /api/v1/users' => ['SUPER_ADMIN'],
        're:/^PATCH \\/api\\/v1\\/users\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        're:/^POST \\/api\\/v1\\/users\\/[^\\/]+\\/recovery$/' => ['SUPER_ADMIN'],
        're:/^POST \\/api\\/v1\\/users\\/[^\\/]+\\/image$/' => ['SUPER_ADMIN'],
        're:/^DELETE \\/api\\/v1\\/users\\/[^\\/]+\\/image$/' => ['SUPER_ADMIN'],
        're:/^DELETE \\/api\\/v1\\/users\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        'GET /api/v1/ambientes' => ['STAFF'],
        'GET /api/v1/ambientes/tree' => ['STAFF'],
        're:/^GET \\/api\\/v1\\/ambientes\\/[^\\/]+$/' => ['STAFF'],
        'POST /api/v1/ambientes' => ['SUPER_ADMIN'],
        're:/^PATCH \\/api\\/v1\\/ambientes\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        're:/^DELETE \\/api\\/v1\\/ambientes\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        'GET /api/v1/zones' => ['STAFF'],
        're:/^GET \\/api\\/v1\\/zones\\/[^\\/]+$/' => ['STAFF'],
        'POST /api/v1/zones' => ['SUPER_ADMIN'],
        're:/^PATCH \\/api\\/v1\\/zones\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        're:/^DELETE \\/api\\/v1\\/zones\\/[^\\/]+$/' => ['SUPER_ADMIN'],
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
        'GET /api/v1/incidents' => ['ADMIN'],
        'POST /api/v1/incidents' => ['TECHNICIAN'],
        're:/^PATCH \\/api\\/v1\\/incidents\\/[^\\/]+$/' => ['SUPER_ADMIN'],
        'GET /api/v1/settings' => ['ADMIN'],
        'POST /api/v1/settings' => ['ADMIN'],
    ];
};
