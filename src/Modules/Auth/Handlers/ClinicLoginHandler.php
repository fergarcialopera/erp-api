<?php

declare(strict_types=1);

namespace App\Modules\Auth\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Validators\ClinicLoginValidator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ClinicLoginHandler
{
    public function __construct(
        private readonly ClinicLoginValidator $validator,
        private readonly AuthService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $dto = $this->validator->validate($request->getParsedBody());
            $result = $this->service->loginClinic($dto['clinic_id'], $dto['password']);

            return ApiResponse::success($request, $result);
        } catch (InvalidArgumentException $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        } catch (RuntimeException $throwable) {
            return ApiResponse::error($request, 401, 'Unauthorized', $throwable->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
