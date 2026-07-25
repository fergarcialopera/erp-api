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

final class PatchProductImportRowsHandler
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

            $importId = (string) $request->getAttribute('import_id', '');
            if ($importId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Import not found');
            }

            $body = $request->getParsedBody();
            $decision = trim((string) ($body['decision'] ?? ''));
            if ($decision === '') {
                return ApiResponse::error($request, 422, 'Unprocessable Entity', 'Missing decision');
            }
            $status = trim((string) ($body['status'] ?? 'conflict'));
            if ($status === '') {
                $status = 'conflict';
            }

            return ApiResponse::success(
                $request,
                $this->service->setBulkDecision($importId, $decision, $status)
            );
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'not found')) {
                return ApiResponse::error($request, 404, 'Not Found', $msg);
            }

            return ApiResponse::error($request, 422, 'Unprocessable Entity', $msg);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
