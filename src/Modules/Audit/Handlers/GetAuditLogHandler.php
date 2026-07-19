<?php

declare(strict_types=1);

namespace App\Modules\Audit\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Audit\Services\AuditLogService;
use Throwable;

final class GetAuditLogHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly AuditLogService $service,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->assertCanView($user);

            $id = trim((string) $request->getAttribute('id', ''));
            if ($id === '') {
                return ApiResponse::error($request, 400, 'Bad Request', 'Missing audit log id');
            }

            $restrictClinicId = $this->access->isSuperAdmin($user)
                ? null
                : $this->access->clinicIdFromToken($user);

            if ($restrictClinicId === null && !$this->access->isSuperAdmin($user)) {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic context');
            }

            $item = $this->service->getById($id, $restrictClinicId !== '' ? $restrictClinicId : null);
            if ($item === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Audit log not found');
            }

            return ApiResponse::success($request, $item);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertCanView(array $user): void
    {
        if (!$this->access->isAdmin($user) && !$this->access->isSuperAdmin($user)) {
            throw new AccessDeniedException('Admin access required');
        }
    }
}
