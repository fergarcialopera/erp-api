<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Suppliers\Services\SupplierService;
use App\Modules\Suppliers\Validators\SupplierValidator;
use Throwable;

final class PatchSupplierHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly SupplierValidator $validator,
        private readonly SupplierService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $id = (string) $request->getAttribute('supplier_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Supplier not found');
            }

            $dto = $this->validator->validatePatch($request->getParsedBody());
            $updated = $this->service->patch($id, $dto, AuditActor::fromUser($user));
            if ($updated === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Supplier not found');
            }

            return ApiResponse::success($request, $updated);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
