<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Infrastructure\Storage\LocalImageStorage;
use App\Modules\Clinic\Services\ClinicService;
use RuntimeException;
use Throwable;

final class UploadClinicImageHandler
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

            $file = $_FILES['image'] ?? null;
            if (!is_array($file)) {
                throw new RuntimeException('Missing image file');
            }

            $clinic = $this->service->getById($clinicId);
            if ($clinic === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $path = $this->storage->storeClinicImage($clinicId, $file);
            $updated = $this->service->updateImagePath($clinicId, $path);

            return ApiResponse::success($request, $updated ?? []);
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
