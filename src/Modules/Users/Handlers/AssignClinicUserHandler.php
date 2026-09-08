<?php

declare(strict_types=1);

namespace App\Modules\Users\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Exceptions\ConflictException;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Users\Services\UserService;
use InvalidArgumentException;
use Throwable;

final class AssignClinicUserHandler
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
            if ($clinicId === '' || $this->clinics->getById($clinicId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $body = $request->getParsedBody();
            $userId = trim((string) ($body['user_id'] ?? ''));
            if ($userId === '') {
                throw new InvalidArgumentException('user_id is required');
            }

            $assigned = $this->service->assignToClinic($clinicId, $userId, AuditActor::fromUser($user));
            if ($assigned === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'User not found');
            }

            return ApiResponse::success($request, $assigned, status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (ConflictException $e) {
            return ApiResponse::error($request, 409, 'Conflict', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
