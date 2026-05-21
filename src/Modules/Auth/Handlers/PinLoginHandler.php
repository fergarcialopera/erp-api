<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\JsonResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Domain\Auth\PinLockedException;
use App\Domain\Auth\UserLockedException;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Validators\PinLoginValidator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PinLoginHandler
{
    public function __construct(
        private readonly PinLoginValidator $validator,
        private readonly AuthService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $clinic = (array) $request->getAttribute('clinic', []);
            $clinicId = (string) ($clinic['clinic_id'] ?? '');
            if ($clinicId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic session');
            }

            $dto = $this->validator->validate($request->getParsedBody());
            $result = $this->service->loginPin($clinicId, $dto['user_id'], $dto['pin']);

            return ApiResponse::success($request, $result);
        } catch (PinLockedException $throwable) {
            return $this->pinLockedResponse($request, $throwable);
        } catch (UserLockedException $throwable) {
            return ApiResponse::error($request, 423, 'Locked', $throwable->getMessage());
        } catch (InvalidArgumentException $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        } catch (RuntimeException $throwable) {
            return ApiResponse::error($request, 401, 'Unauthorized', $throwable->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }

    private function pinLockedResponse(Request $request, PinLockedException $exception): JsonResponse
    {
        return new JsonResponse([
            'status' => 423,
            'title' => 'Pin Locked',
            'detail' => $exception->getMessage(),
            'instance' => $request->getUri(),
            'request_id' => $request->getAttribute('request_id'),
            'meta' => [
                'fallback' => 'classic_login',
                'failed_attempts' => $exception->failedAttempts,
            ],
        ], 423);
    }
}
