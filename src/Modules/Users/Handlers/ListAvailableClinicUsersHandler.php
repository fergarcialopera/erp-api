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

final class ListAvailableClinicUsersHandler
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
            $this->access->assertSuperAdmin($user);

            $clinicId = (string) $request->getAttribute('clinic_id', '');
            if ($clinicId === '' || $this->clinics->getById($clinicId) === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Clinic not found');
            }

            $qp = $request->getQueryParams();
            $search = null;
            if (array_key_exists('search', $qp)) {
                $search = trim((string) $qp['search']);
                if ($search === '') {
                    $search = null;
                } elseif (mb_strlen($search) > 100) {
                    throw new InvalidArgumentException('Invalid search filter');
                }
            }

            return ApiResponse::success($request, $this->service->listAvailableForClinic($search));
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
