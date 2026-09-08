<?php

declare(strict_types=1);

namespace App\Modules\Products\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Products\Services\ProductService;
use InvalidArgumentException;
use Throwable;

final class ListProductClinicsHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ProductService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $productId = (string) $request->getAttribute('product_id', '');
            if ($productId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            $qp = $request->getQueryParams();
            $visible = null;
            if (array_key_exists('visible', $qp)) {
                $parsed = filter_var($qp['visible'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    throw new InvalidArgumentException('Invalid visible filter');
                }
                $visible = (bool) $parsed;
            }
            $search = null;
            if (array_key_exists('search', $qp)) {
                $search = trim((string) $qp['search']);
                if ($search === '') {
                    $search = null;
                } elseif (mb_strlen($search) > 100) {
                    throw new InvalidArgumentException('Invalid search filter');
                }
            }

            $clinics = $this->service->listClinicsForProduct($productId, $visible, $search);
            if ($clinics === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Product not found');
            }

            return ApiResponse::success($request, $clinics);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
