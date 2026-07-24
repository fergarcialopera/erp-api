<?php

declare(strict_types=1);

namespace App\Modules\Subcategories\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Subcategories\Services\SubcategoryService;
use Throwable;

final class DeleteSubcategoryHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly SubcategoryService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $id = (string) $request->getAttribute('subcategory_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Subcategory not found');
            }

            if (!$this->service->softDelete($id, AuditActor::fromUser($user))) {
                return ApiResponse::error($request, 404, 'Not Found', 'Subcategory not found');
            }

            return ApiResponse::success($request, ['deleted' => true]);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
