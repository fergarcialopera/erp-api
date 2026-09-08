<?php

declare(strict_types=1);

namespace App\Modules\Users\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Auth\ClinicAccessService;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Users\Services\UserService;
use InvalidArgumentException;
use Throwable;

final class ListClinicUsersHandler
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ClinicService $clinics,
        private readonly UserService $service
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) $request->getAttribute('clinic_id', '');
            if ($clinicId === '' || $this->clinics->getById($clinicId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $this->access->assertAdminOfClinic($user, $clinicId);

            $qp = $request->getQueryParams();
            $isActive = $this->parseOptionalBool($qp, 'is_active');
            $search = $this->parseSearch($qp);

            return ApiResponse::success($request, $this->service->list($clinicId, $isActive, $search));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $qp
     */
    private function parseOptionalBool(array $qp, string $key): ?bool
    {
        if (!array_key_exists($key, $qp)) {
            return null;
        }
        $bool = filter_var($qp[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new InvalidArgumentException('Invalid ' . $key);
        }

        return (bool) $bool;
    }

    /**
     * @param array<string, mixed> $qp
     */
    private function parseSearch(array $qp): ?string
    {
        if (!array_key_exists('search', $qp)) {
            return null;
        }
        $search = trim((string) $qp['search']);
        if ($search === '') {
            return null;
        }
        if (mb_strlen($search) > 100) {
            throw new InvalidArgumentException('Invalid search filter');
        }

        return $search;
    }
}
