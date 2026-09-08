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

final class BulkPatchClinicProductsHandler
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
            $clinicId = (string) $request->getAttribute('clinic_id', '');
            if ($clinicId === '' || $this->clinics->getById($clinicId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $this->access->assertAdminOfClinic($user, $clinicId);

            $body = $request->getParsedBody();
            $visible = $this->validator->parseRequiredVisible($body);
            $onlyActive = $this->validator->parseOnlyActiveCatalog($body);
            $productIds = $this->validator->parseOptionalIdList($body, 'product_ids');

            return ApiResponse::success(
                $request,
                $this->service->bulkSetVisibilityForClinic(
                    $clinicId,
                    $visible,
                    $onlyActive,
                    $productIds,
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
