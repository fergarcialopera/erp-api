<?php

declare(strict_types=1);

namespace App\Modules\ProductTags\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\ProductTags\Services\ProductTagService;
use Throwable;

final class GetProductTagHandler
{
    public function __construct(private readonly ProductTagService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('product_tag_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product tag not found');
            }

            $tag = $this->service->get($id);
            if ($tag === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product tag not found');
            }

            return ApiResponse::success($request, $tag);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
