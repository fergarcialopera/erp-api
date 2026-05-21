<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\RecoveryService;
use App\Modules\Auth\Validators\RecoveryValidator;
use InvalidArgumentException;
use Throwable;

final class RequestRecoveryUserHandler
{
    public function __construct(
        private readonly RecoveryValidator $validator,
        private readonly RecoveryService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $dto = $this->validator->validateUserRequest($request->getParsedBody());
            $this->service->requestUserRecovery($dto['email'], $dto['type']);

            return ApiResponse::success($request, [
                'message' => 'If the email exists, a recovery link will be sent.',
            ], status: 202);
        } catch (InvalidArgumentException $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
