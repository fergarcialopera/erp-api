<?php

declare(strict_types=1);

namespace App\Modules\Products\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Validators\ProductValidator;
use InvalidArgumentException;
use Throwable;

final class PatchProductClinicVisibilityHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ClinicService $clinics,
        private readonly ProductValidator $validator,
        private readonly ProductService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $productId = (string) $request->getAttribute('product_id', '');
            $clinicId = (string) $request->getAttribute('clinic_id', '');
            if ($productId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }
            if ($clinicId === '' || $this->clinics->getById($clinicId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $visible = $this->validator->parseRequiredVisible($request->getParsedBody());
            $link = $this->service->upsertClinicVisibilityLink(
                $clinicId,
                $productId,
                $visible,
                AuditActor::fromUser($user)
            );
            if ($link === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            return ApiResponse::success($request, $link);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
