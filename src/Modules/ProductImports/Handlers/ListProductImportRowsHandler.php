<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\ProductImports\Services\ProductImportService;
use RuntimeException;
use Throwable;

final class ListProductImportRowsHandler
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

            $result = $this->service->listRows($id, $request->getQueryParams());

            return ApiResponse::success($request, $result['items'], meta: $result['meta']);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Import not found') {
                return ApiResponse::error($request, 404, 'Not Found', $e->getMessage());
            }

            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
