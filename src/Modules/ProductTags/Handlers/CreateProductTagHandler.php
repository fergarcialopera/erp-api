<?php

declare(strict_types=1);

namespace App\Modules\ProductTags\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\ProductTags\Services\ProductTagService;
use App\Modules\ProductTags\Validators\ProductTagValidator;
use Throwable;

final class CreateProductTagHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ProductTagValidator $validator,
        private readonly ProductTagService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $dto = $this->validator->validateCreate($request->getParsedBody());

            return ApiResponse::success($request, $this->service->create($dto, AuditActor::fromUser($user)), status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
