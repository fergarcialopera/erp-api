<?php

declare(strict_types=1);

namespace App\Modules\Brands\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Brands\Services\BrandService;
use InvalidArgumentException;
use Throwable;

final class AttachBrandSupplierHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly BrandService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $brandId = (string) $request->getAttribute('brand_id', '');
            if ($brandId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Brand not found');
            }

            $body = $request->getParsedBody();
            $supplierId = trim((string) ($body['supplier_id'] ?? ''));
            if ($supplierId === '') {
                throw new InvalidArgumentException('supplier_id is required');
            }

            $isActive = true;
            if (array_key_exists('is_active', $body)) {
                $raw = $body['is_active'];
                $parsed = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    throw new InvalidArgumentException('Invalid is_active');
                }
                $isActive = (bool) $parsed;
            }

            $link = $this->service->attachSupplier($brandId, $supplierId, $isActive);
            if ($link === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Brand or supplier not found');
            }

            return ApiResponse::success($request, $link, status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
