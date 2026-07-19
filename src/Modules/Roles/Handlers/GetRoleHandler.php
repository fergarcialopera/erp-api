<?php

declare(strict_types=1);

namespace App\Modules\Roles\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Roles\Services\RoleService;
use Throwable;

final class GetRoleHandler
{
    public function __construct(private readonly RoleService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('role_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Role not found');
            }

            $role = $this->service->get($id);
            if ($role === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Role not found');
            }

            return ApiResponse::success($request, $role);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
