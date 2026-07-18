<?php

declare(strict_types=1);

namespace App\Modules\Ambientes\Handlers;

use App\Application\Audit\AuditActor;
use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\RequestClinicResolver;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Ambientes\Services\AmbienteService;
use InvalidArgumentException;
use Throwable;

final class PatchClinicAmbienteVisibilityHandler
{
    public function __construct(
        private readonly RequestClinicResolver $clinicResolver,
        private readonly AmbienteService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = $this->clinicResolver->requireClinicIdForVisibility($request, $user);

            $ambienteId = (string) $request->getAttribute('ambiente_id', '');
            if ($ambienteId === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente not found');
            }

            $body = $request->getParsedBody();
            if (!array_key_exists('visible', $body)) {
                throw new InvalidArgumentException('visible is required');
            }
            $visible = is_bool($body['visible'])
                ? $body['visible']
                : filter_var($body['visible'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($visible === null) {
                throw new InvalidArgumentException('Invalid visible');
            }

            $ambiente = $this->service->setClinicVisibility($clinicId, $ambienteId, (bool) $visible, AuditActor::fromUser($user));
            if ($ambiente === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Ambiente not found');
            }

            return ApiResponse::success($request, $ambiente);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $throwable->getMessage());
        }
    }
}
