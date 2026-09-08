<?php

declare(strict_types=1);

namespace App\Modules\Users\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Users\DTOs\PatchUserDTO;
use App\Modules\Users\Services\UserService;
use InvalidArgumentException;
use Throwable;

final class PatchClinicUserHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ClinicService $clinics,
        private readonly UserService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $clinicId = (string) $request->getAttribute('clinic_id', '');
            $userId = (string) $request->getAttribute('user_id', '');
            if ($clinicId === '' || $this->clinics->getById($clinicId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }
            if ($userId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'User not found');
            }

            $body = $request->getParsedBody();
            if (!array_key_exists('is_active', $body)) {
                throw new InvalidArgumentException('is_active is required');
            }
            $raw = $body['is_active'];
            $isActive = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($isActive === null) {
                throw new InvalidArgumentException('Invalid is_active');
            }

            if (!$this->service->belongsToClinic($userId, $clinicId)) {
                return ApiResponse::error($request, 404, 'Not Found', 'User not found');
            }

            $updated = $this->service->patch(
                $userId,
                new PatchUserDTO(null, null, (bool) $isActive, null, null, null),
                AuditActor::fromUser($user)
            );
            if ($updated === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'User not found');
            }

            return ApiResponse::success($request, $updated);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
