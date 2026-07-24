<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Application\Audit\AuditRequestContext;
use App\Application\Support\PinValidator;
use App\Domain\Auth\PinLockedException;
use App\Domain\Auth\UserLockedException;
use App\Infrastructure\Auth\LoginAttemptService;
use App\Infrastructure\Auth\TokenService;
use App\Modules\Audit\Services\AuditLogService;
use App\Modules\Auth\DTOs\LoginDTO;
use App\Modules\Auth\Mappers\AuthMapper;
use PDO;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TokenService $tokenService,
        private readonly LoginAttemptService $loginAttempts,
        private readonly AuthMapper $mapper,
        private readonly AuditLogService $auditLogs,
    ) {
    }

    public function listVisibleClinics(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, image_path FROM clinics WHERE visible = TRUE ORDER BY name ASC'
        );

        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->mapper->toClinicCard($row), $rows);
    }

    public function loginClinic(string $clinicId, string $password, AuditRequestContext $context): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, image_path, password_hash, visible FROM clinics WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $clinicId]);
        $clinic = $stmt->fetch();

        if (!$clinic || !(bool) $clinic['visible']) {
            $this->auditLogs->recordFailure('clinic_login', 'clinic_not_found', $context, $clinicId);
            throw new RuntimeException('Invalid credentials');
        }

        $hash = (string) ($clinic['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            $this->auditLogs->recordFailure('clinic_login', 'invalid_credentials', $context, (string) $clinic['id']);
            throw new RuntimeException('Invalid credentials');
        }

        $this->auditLogs->recordSuccess('clinic_login', $context, (string) $clinic['id']);
        $token = $this->tokenService->issueClinicToken((string) $clinic['id']);

        return $this->mapper->toClinicLoginResponse(
            $token,
            $clinic,
            $this->tokenService->getClinicTtlSeconds()
        );
    }

    public function listStaff(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT u.id, u.name, u.email, u.role, u.image_path
             FROM users u
             LEFT JOIN user_clinics uc ON uc.user_id = u.id
             WHERE u.role <> \'SUPER_ADMIN\'
               AND u.is_active = TRUE
               AND u.is_locked = FALSE
               AND (u.clinic_id::text = :clinic_id OR uc.clinic_id::text = :clinic_id)
             ORDER BY u.name ASC NULLS LAST, u.email ASC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->mapper->toStaffCard($row), $rows);
    }

    public function loginPin(string $clinicId, string $userId, string $pin, AuditRequestContext $context): array
    {
        PinValidator::assertValid($pin);

        $user = $this->findActiveUserInClinic($clinicId, $userId);
        if ($user === null) {
            $this->auditLogs->recordFailure('pin_login', 'invalid_credentials', $context, $clinicId);
            throw new RuntimeException('Invalid credentials');
        }

        $resolvedUserId = (string) $user['id'];

        if ((bool) $user['is_locked']) {
            $this->auditLogs->recordFailure('pin_login', 'user_locked', $context, $clinicId, $resolvedUserId);
            throw new UserLockedException();
        }

        if ($this->loginAttempts->isPinLocked($resolvedUserId)) {
            $this->auditLogs->recordFailure('pin_login', 'pin_locked', $context, $clinicId, $resolvedUserId);
            throw new PinLockedException($this->loginAttempts->getPinFailures($resolvedUserId));
        }

        $pinHash = (string) ($user['pin_hash'] ?? '');
        if ($pinHash === '' || !password_verify($pin, $pinHash)) {
            $failures = $this->loginAttempts->recordPinFailure($resolvedUserId);
            if ($failures >= $this->loginAttempts->maxAttempts()) {
                $this->auditLogs->recordFailure('pin_login', 'pin_locked', $context, $clinicId, $resolvedUserId);
                throw new PinLockedException($failures);
            }

            $this->auditLogs->recordFailure('pin_login', 'invalid_credentials', $context, $clinicId, $resolvedUserId);
            throw new RuntimeException('Invalid credentials');
        }

        $this->loginAttempts->clearAllFailures($resolvedUserId);
        $this->auditLogs->recordSuccess('pin_login', $context, $clinicId, $resolvedUserId);

        return $this->issueUserSession($user, $clinicId);
    }

    public function login(LoginDTO $dto, ?string $clinicIdFromSession, AuditRequestContext $context): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, password_hash, role, operational_role_id, is_active, is_locked, pin_hash
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $dto->email]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->auditLogs->recordFailure('email_login', 'invalid_credentials', $context, $clinicIdFromSession);
            throw new RuntimeException('Invalid credentials');
        }

        $resolvedUserId = (string) $user['id'];
        $resolvedClinicId = $user['clinic_id'] !== null ? (string) $user['clinic_id'] : $clinicIdFromSession;

        if (!(bool) $user['is_active']) {
            $this->auditLogs->recordFailure('email_login', 'user_inactive', $context, $resolvedClinicId, $resolvedUserId);
            throw new RuntimeException('Invalid credentials');
        }

        if ((bool) $user['is_locked']) {
            $this->auditLogs->recordFailure('email_login', 'user_locked', $context, $resolvedClinicId, $resolvedUserId);
            throw new UserLockedException();
        }

        if ($clinicIdFromSession !== null && !$this->userHasClinicAccess($user, $clinicIdFromSession)) {
            $this->auditLogs->recordFailure('email_login', 'invalid_credentials', $context, $clinicIdFromSession, $resolvedUserId);
            throw new RuntimeException('Invalid credentials');
        }

        if (!password_verify($dto->password, (string) $user['password_hash'])) {
            $failures = $this->loginAttempts->recordLoginFailure($resolvedUserId);
            if ($failures >= $this->loginAttempts->maxAttempts()) {
                $this->lockUser($resolvedUserId);
                $this->auditLogs->recordFailure('email_login', 'user_locked', $context, $resolvedClinicId, $resolvedUserId);
                throw new UserLockedException();
            }

            $this->auditLogs->recordFailure('email_login', 'invalid_credentials', $context, $resolvedClinicId, $resolvedUserId);
            throw new RuntimeException('Invalid credentials');
        }

        $this->loginAttempts->clearAllFailures($resolvedUserId);
        $this->auditLogs->recordSuccess('email_login', $context, $resolvedClinicId, $resolvedUserId);

        return $this->issueUserSession($user, $clinicIdFromSession);
    }

    public function validateUserToken(string $token): ?array
    {
        return $this->tokenService->validateUserToken($token);
    }

    public function validateClinicToken(string $token): ?array
    {
        return $this->tokenService->validateClinicToken($token);
    }

    public function logoutUser(string $token, AuditRequestContext $context): void
    {
        $payload = $this->tokenService->validateUserToken($token);
        $this->tokenService->invalidateUserToken($token);

        if (!is_array($payload)) {
            return;
        }

        $clinicId = trim((string) ($payload['clinic_id'] ?? ''));
        $userId = trim((string) ($payload['user_id'] ?? ''));

        $this->auditLogs->recordSuccess(
            'logout',
            $context,
            $clinicId !== '' ? $clinicId : null,
            $userId !== '' ? $userId : null,
        );
    }

    public function logoutClinic(string $token, AuditRequestContext $context): void
    {
        $payload = $this->tokenService->validateClinicToken($token);
        $this->tokenService->invalidateClinicToken($token);

        if (!is_array($payload)) {
            return;
        }

        $clinicId = trim((string) ($payload['clinic_id'] ?? ''));
        $this->auditLogs->recordSuccess(
            'clinic_logout',
            $context,
            $clinicId !== '' ? $clinicId : null,
        );
    }

    private function issueUserSession(array $user, ?string $activeClinicId = null): array
    {
        $payload = $this->mapper->toTokenPayload($user);
        if (($payload['clinic_id'] ?? '') === '' && $activeClinicId !== null && $activeClinicId !== '') {
            $payload['clinic_id'] = $activeClinicId;
        }
        $token = $this->tokenService->issueUserToken($payload);

        return $this->mapper->toUserLoginResponse(
            $token,
            $payload,
            $this->tokenService->getUserTtlSeconds()
        );
    }

    private function findActiveUserInClinic(string $clinicId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.clinic_id, u.name, u.email, u.password_hash, u.role, u.operational_role_id, u.is_active, u.is_locked, u.pin_hash
             FROM users u
             LEFT JOIN user_clinics uc ON uc.user_id = u.id
             WHERE u.id::text = :id
               AND u.is_active = TRUE
               AND u.role <> \'SUPER_ADMIN\'
               AND (u.clinic_id::text = :clinic_id OR uc.clinic_id::text = :clinic_id)
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $userId]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function userHasClinicAccess(array $user, string $clinicId): bool
    {
        if (strtoupper((string) ($user['role'] ?? '')) === 'SUPER_ADMIN') {
            return true;
        }

        if ((string) ($user['clinic_id'] ?? '') === $clinicId) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_clinics WHERE user_id = :user_id AND clinic_id::text = :clinic_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $user['id'], 'clinic_id' => $clinicId]);

        return (bool) $stmt->fetch();
    }

    private function lockUser(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_locked = TRUE, locked_at = NOW(), updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $userId]);
    }
}
