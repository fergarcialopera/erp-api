<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\RecoveryService;
use App\Modules\Auth\Validators\RecoveryValidator;
use InvalidArgumentException;
use Throwable;

final class RequestClinicRecoveryHandler
{
    public function __construct(
        private readonly RecoveryValidator $validator,
        private readonly RecoveryService $recoveryService
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $email = (string) ($user['email'] ?? '');
            if ($email === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing user email');
            }

            $this->recoveryService->requestClinicPasswordByAdminEmail($email);

            return ApiResponse::success($request, [
                'message' => 'Recovery email sent if applicable.',
            ], status: 202);
        } catch (InvalidArgumentException $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
