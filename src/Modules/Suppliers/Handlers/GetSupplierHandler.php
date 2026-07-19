<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Suppliers\Services\SupplierService;
use Throwable;

final class GetSupplierHandler
{
    public function __construct(private readonly SupplierService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('supplier_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Supplier not found');
            }

            $supplier = $this->service->get($id);
            if ($supplier === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Supplier not found');
            }

            return ApiResponse::success($request, $supplier);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
