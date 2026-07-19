<?php

declare(strict_types=1);

namespace App\Modules\DispensingTypes\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\DispensingTypes\Services\DispensingTypeService;
use Throwable;

final class GetDispensingTypeHandler
{
    public function __construct(private readonly DispensingTypeService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('dispensing_type_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Dispensing type not found');
            }

            $dispensingType = $this->service->get($id);
            if ($dispensingType === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Dispensing type not found');
            }

            return ApiResponse::success($request, $dispensingType);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
