<?php

declare(strict_types=1);

namespace App\Modules\Products\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Validators\ProductValidator;
use InvalidArgumentException;
use Throwable;

final class BulkPatchProductClinicsHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
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
            if ($productId === '' || $this->service->getGlobal($productId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            $body = $request->getParsedBody();
            $visible = $this->validator->parseRequiredVisible($body);
            $clinicIds = $this->validator->parseOptionalIdList($body, 'clinic_ids');

            return ApiResponse::success(
                $request,
                $this->service->bulkSetVisibilityForProduct(
                    $productId,
                    $visible,
                    $clinicIds,
                    AuditActor::fromUser($user)
                )
            );
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
