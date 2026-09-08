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

final class CreateProductImportHandler
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

            $userId = (string) ($user['user_id'] ?? $user['id'] ?? '');
            if ($userId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing user id');
            }

            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Missing file field (multipart field name: file)');
            }

            $import = $this->service->analyzeUpload($file, $userId);

            return ApiResponse::success($request, $import, status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
