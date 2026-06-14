<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Application\Support\PinValidator;
use App\Domain\Auth\PinLockedException;
use App\Domain\Auth\UserLockedException;
use App\Infrastructure\Auth\LoginAttemptService;
use App\Infrastructure\Auth\TokenService;
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
        private readonly AuthMapper $mapper
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

    public function loginClinic(string $clinicId, string $password): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, image_path, password_hash, visible FROM clinics WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $clinicId]);
        $clinic = $stmt->fetch();

        if (!$clinic || !(bool) $clinic['visible']) {
            throw new RuntimeException('Invalid credentials');
        }

        $hash = (string) ($clinic['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new RuntimeException('Invalid credentials');
        }

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

    public function loginPin(string $clinicId, string $userId, string $pin): array
    {
        PinValidator::assertValid($pin);

        $user = $this->findActiveUserInClinic($clinicId, $userId);
        if ($user === null) {
            throw new RuntimeException('Invalid credentials');
        }

        if ((bool) $user['is_locked']) {
            throw new UserLockedException();
        }

        if ($this->loginAttempts->isPinLocked((string) $user['id'])) {
            throw new PinLockedException($this->loginAttempts->getPinFailures((string) $user['id']));
        }

        $pinHash = (string) ($user['pin_hash'] ?? '');
        if ($pinHash === '' || !password_verify($pin, $pinHash)) {
            $failures = $this->loginAttempts->recordPinFailure((string) $user['id']);
            if ($failures >= $this->loginAttempts->maxAttempts()) {
                throw new PinLockedException($failures);
            }

            throw new RuntimeException('Invalid credentials');
        }

        $this->loginAttempts->clearAllFailures((string) $user['id']);

        return $this->issueUserSession($user, $clinicId);
    }

    public function login(LoginDTO $dto, ?string $clinicIdFromSession = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, password_hash, role, is_active, is_locked, pin_hash
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $dto->email]);
        $user = $stmt->fetch();

        if (!$user || !(bool) $user['is_active']) {
            throw new RuntimeException('Invalid credentials');
        }

        if ((bool) $user['is_locked']) {
            throw new UserLockedException();
        }

        if ($clinicIdFromSession !== null && !$this->userHasClinicAccess($user, $clinicIdFromSession)) {
            throw new RuntimeException('Invalid credentials');
        }

        if (!password_verify($dto->password, (string) $user['password_hash'])) {
            $failures = $this->loginAttempts->recordLoginFailure((string) $user['id']);
            if ($failures >= $this->loginAttempts->maxAttempts()) {
                $this->lockUser((string) $user['id']);
                throw new UserLockedException();
            }

            throw new RuntimeException('Invalid credentials');
        }

        $this->loginAttempts->clearAllFailures((string) $user['id']);

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

    public function logoutUser(string $token): void
    {
        $this->tokenService->invalidateUserToken($token);
    }

    public function logoutClinic(string $token): void
    {
        $this->tokenService->invalidateClinicToken($token);
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
            'SELECT u.id, u.clinic_id, u.name, u.email, u.password_hash, u.role, u.is_active, u.is_locked, u.pin_hash
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
