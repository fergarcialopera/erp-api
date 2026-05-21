<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\RecoveryService;
use App\Modules\Auth\Validators\RecoveryValidator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ConfirmRecoveryHandler
{
    public function __construct(
        private readonly RecoveryValidator $validator,
        private readonly RecoveryService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $dto = $this->validator->validateConfirm($request->getParsedBody());
            $this->service->confirm(
                $dto['token'],
                $dto['type'],
                $dto['new_password'],
                $dto['new_pin']
            );

            return ApiResponse::success($request, ['confirmed' => true]);
        } catch (InvalidArgumentException $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        } catch (RuntimeException $throwable) {
            return ApiResponse::error($request, 400, 'Bad Request', $throwable->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
