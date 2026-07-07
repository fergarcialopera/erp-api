<?php

declare(strict_types=1);

namespace App\Modules\Users\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Infrastructure\Storage\LocalImageStorage;
use App\Modules\Users\Services\UserService;
use Throwable;

final class DeleteUserImageHandler
{
    public function __construct(
        private readonly UserService $service,
        private readonly LocalImageStorage $storage
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) ($user['clinic_id'] ?? '');
            $actorRole = strtoupper((string) ($user['role'] ?? ''));
            $targetUserId = (string) $request->getAttribute('user_id', '');

            if ($clinicId === '' || $targetUserId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing context');
            }

            $actorUserId = (string) ($user['user_id'] ?? '');
            if ($actorRole !== 'ADMIN' && $actorUserId !== $targetUserId) {
                return ApiResponse::error($request, 403, 'Forbidden', 'Insufficient role');
            }

            $target = $this->service->get($targetUserId);
            if ($target === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'User not found');
            }

            $this->storage->deleteByPublicPath(isset($target['image_path']) ? (string) $target['image_path'] : null);
            $updated = $this->service->updateImagePath($clinicId, $targetUserId, null, AuditActor::fromUser($user));

            return ApiResponse::success($request, $updated ?? []);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
