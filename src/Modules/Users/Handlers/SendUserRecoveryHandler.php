<?php

declare(strict_types=1);

namespace App\Modules\Users\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Auth\Services\RecoveryService;
use App\Modules\Users\Validators\UserValidator;
use InvalidArgumentException;
use Throwable;

final class SendUserRecoveryHandler
{
    public function __construct(
        private readonly UserValidator $validator,
        private readonly RecoveryService $recoveryService
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $admin = (array) $request->getAttribute('user', []);
            $targetUserId = (string) $request->getAttribute('user_id', '');

            if ($targetUserId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing context');
            }

            $type = $this->validator->validateRecoveryRequest($request->getParsedBody());
            $this->recoveryService->sendUserRecoveryByAdmin(
                $targetUserId,
                (string) ($admin['clinic_id'] ?? ''),
                $type,
                (string) ($admin['user_id'] ?? '')
            );

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
