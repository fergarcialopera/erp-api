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
            'SELECT id, name, email, role, image_path
             FROM users
             WHERE clinic_id::text = :clinic_id AND is_active = TRUE AND is_locked = FALSE
             ORDER BY name ASC NULLS LAST, email ASC'
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

        return $this->issueUserSession($user);
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

        if ($clinicIdFromSession !== null && (string) $user['clinic_id'] !== $clinicIdFromSession) {
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

        return $this->issueUserSession($user);
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

    private function issueUserSession(array $user): array
    {
        $payload = $this->mapper->toTokenPayload($user);
        $token = $this->tokenService->issueUserToken($payload);

        return $this->mapper->toUserLoginResponse(
            $token,
            $user,
            $this->tokenService->getUserTtlSeconds()
        );
    }

    private function findActiveUserInClinic(string $clinicId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, password_hash, role, is_active, is_locked, pin_hash
             FROM users
             WHERE clinic_id::text = :clinic_id AND id::text = :id AND is_active = TRUE
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $userId]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    private function lockUser(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_locked = TRUE, locked_at = NOW(), updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['id' => $userId]);
    }
}
