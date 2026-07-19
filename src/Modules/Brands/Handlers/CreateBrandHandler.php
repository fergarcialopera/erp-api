<?php

declare(strict_types=1);

namespace App\Modules\Brands\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Brands\Services\BrandService;
use App\Modules\Brands\Validators\BrandValidator;
use Throwable;

final class CreateBrandHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly BrandValidator $validator,
        private readonly BrandService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $dto = $this->validator->validateCreate($request->getParsedBody());

            return ApiResponse::success($request, $this->service->create($dto), status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
