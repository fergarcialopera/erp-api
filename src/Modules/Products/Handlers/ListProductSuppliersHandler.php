<?php

declare(strict_types=1);

namespace App\Modules\Products\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Products\Services\ProductService;
use RuntimeException;
use Throwable;

final class ListProductSuppliersHandler
{
    public function __construct(private readonly ProductService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $productId = (string) $request->getAttribute('product_id', '');
            if ($productId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            return ApiResponse::success($request, $this->service->listSuppliers($productId));
        } catch (RuntimeException $e) {
            return ApiResponse::error($request, 404, 'Not Found', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
