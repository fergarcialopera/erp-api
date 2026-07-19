<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Audit\AuditRequestContext;
use App\Application\Http\ApiResponse;
use App\Application\Http\JsonResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Domain\Auth\UserLockedException;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Validators\LoginValidator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class LoginHandler
{
    public function __construct(
        private readonly LoginValidator $validator,
        private readonly AuthService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $dto = $this->validator->validate($request->getParsedBody());
            $clinic = (array) $request->getAttribute('clinic', []);
            $clinicId = isset($clinic['clinic_id']) ? (string) $clinic['clinic_id'] : null;
            $result = $this->service->login(
                $dto,
                $clinicId !== '' ? $clinicId : null,
                AuditRequestContext::fromRequest($request),
            );

            return ApiResponse::success($request, $result);
        } catch (UserLockedException $throwable) {
            return new JsonResponse([
                'status' => 423,
                'title' => 'Locked',
                'detail' => $throwable->getMessage(),
                'instance' => $request->getUri(),
                'request_id' => $request->getAttribute('request_id'),
                'meta' => ['locked' => true],
            ], 423);
        } catch (InvalidArgumentException $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        } catch (RuntimeException $throwable) {
            return ApiResponse::error($request, 401, 'Unauthorized', $throwable->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 401, 'Unauthorized', $throwable->getMessage());
        }
    }
}
