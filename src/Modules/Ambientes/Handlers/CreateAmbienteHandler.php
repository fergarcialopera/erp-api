<?php

namespace App\Modules\Ambientes\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Ambientes\Services\AmbienteService;
use App\Modules\Ambientes\Validators\AmbienteValidator;
use Throwable;

final class CreateAmbienteHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly AmbienteValidator $validator,
        private readonly AmbienteService $service
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
