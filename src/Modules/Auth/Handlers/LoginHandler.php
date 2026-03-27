<?php

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Validators\LoginValidator;
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
            $result = $this->service->login($dto);
            return ApiResponse::success($request, $result);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 401, 'Unauthorized', $throwable->getMessage());
        }
    }
}
