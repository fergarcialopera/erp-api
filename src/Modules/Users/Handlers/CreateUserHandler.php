<?php

namespace App\Modules\Users\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Users\Services\UserService;
use App\Modules\Users\Validators\UserValidator;
use Throwable;

final class CreateUserHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly UserValidator $validator,
        private readonly UserService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $this->access->assertSuperAdmin($user);

            $dto = $this->validator->validateCreate($request->getParsedBody());
            $created = $this->service->create($dto);

            return ApiResponse::success($request, $created, status: 201);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
