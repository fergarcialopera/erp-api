<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Clinic\Validators\ClinicValidator;
use Throwable;

final class PatchClinicByIdHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ClinicValidator $validator,
        private readonly ClinicService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $clinicId = (string) $request->getAttribute('clinic_id', '');
            if ($clinicId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $dto = $this->validator->validatePatch($request->getParsedBody());
            $updated = $this->service->patch($clinicId, $dto->visible, $dto->password, $dto->name, AuditActor::fromUser($user));
            if ($updated === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            return ApiResponse::success($request, $updated);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
