<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\ProductImports\Services\ProductImportService;
use Throwable;

final class GetProductImportHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ProductImportService $service,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $id = (string) $request->getAttribute('import_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Import not found');
            }

            $import = $this->service->get($id);
            if ($import === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Import not found');
            }

            return ApiResponse::success($request, $import);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
