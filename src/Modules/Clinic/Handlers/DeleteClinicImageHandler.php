<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Infrastructure\Storage\LocalImageStorage;
use App\Modules\Clinic\Services\ClinicService;
use Throwable;

final class DeleteClinicImageHandler
{
    public function __construct(
        private readonly ClinicService $service,
        private readonly LocalImageStorage $storage
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) ($user['clinic_id'] ?? '');
            if ($clinicId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic_id in user context');
            }

            $clinic = $this->service->getById($clinicId);
            if ($clinic === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $this->storage->deleteByPublicPath(isset($clinic['image_path']) ? (string) $clinic['image_path'] : null);
            $updated = $this->service->updateImagePath($clinicId, null);

            return ApiResponse::success($request, $updated ?? []);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
