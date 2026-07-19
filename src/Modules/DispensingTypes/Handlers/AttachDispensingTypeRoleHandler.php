<?php

declare(strict_types=1);

namespace App\Modules\DispensingTypes\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\DispensingTypes\Services\DispensingTypeService;
use InvalidArgumentException;
use Throwable;

final class AttachDispensingTypeRoleHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly DispensingTypeService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $dispensingTypeId = (string) $request->getAttribute('dispensing_type_id', '');
            if ($dispensingTypeId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Dispensing type not found');
            }

            $body = $request->getParsedBody();
            $roleId = trim((string) ($body['role_id'] ?? ''));
            if ($roleId === '') {
                throw new InvalidArgumentException('role_id is required');
            }

            $link = $this->service->attachRole($dispensingTypeId, $roleId);
            if ($link === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Dispensing type or role not found');
            }

            return ApiResponse::success($request, $link, status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
