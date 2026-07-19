<?php

declare(strict_types=1);

namespace App\Modules\DispensingTypes\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\DispensingTypes\Services\DispensingTypeService;
use Throwable;

final class ListDispensingTypeRolesHandler
{
    public function __construct(private readonly DispensingTypeService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $dispensingTypeId = (string) $request->getAttribute('dispensing_type_id', '');
            if ($dispensingTypeId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Dispensing type not found');
            }

            return ApiResponse::success($request, $this->service->listRoles($dispensingTypeId));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            if ($throwable->getMessage() === 'Dispensing type not found') {
                return ApiResponse::error($request, 404, 'Not Found', 'Dispensing type not found');
            }

            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
