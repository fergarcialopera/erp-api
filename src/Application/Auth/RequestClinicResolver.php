<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Http\Request;

final class RequestClinicResolver
{
    public function __construct(private readonly ClinicAccessService $access)
    {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function requireClinicId(Request $request, array $user): string
    {
        $clinicId = $this->access->clinicIdFromToken($user);
        if ($clinicId === '') {
            throw new AccessDeniedException('Missing clinic_id in user context');
        }

        $this->access->assertClinicAccess($user, $clinicId);

        return $clinicId;
    }

    /**
     * Resuelve la clínica para acciones de visibilidad (ADMIN de clínica o SUPER_ADMIN en cualquiera).
     *
     * @param array<string, mixed> $user
     */
    public function requireClinicIdForVisibility(Request $request, array $user): string
    {
        $clinicId = trim((string) $request->getAttribute('clinic_id', ''));
        if ($clinicId === '') {
            $body = $request->getParsedBody();
            $clinicId = trim((string) ($body['clinic_id'] ?? ''));
        }
        if ($clinicId === '') {
            $clinicId = trim((string) ($request->getQueryParams()['clinic_id'] ?? ''));
        }
        if ($clinicId === '') {
            $clinicId = $this->access->clinicIdFromToken($user);
        }
        if ($clinicId === '') {
            throw new AccessDeniedException('Missing clinic_id');
        }

        $this->access->assertAdminOfClinic($user, $clinicId);

        return $clinicId;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isAdminView(array $user): bool
    {
        return $this->access->isAdmin($user) || $this->access->isSuperAdmin($user);
    }
}
