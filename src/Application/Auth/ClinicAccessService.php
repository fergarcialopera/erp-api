<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\Role;
use PDO;

final class ClinicAccessService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function role(array $user): string
    {
        return Role::normalize((string) ($user['role'] ?? ''));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isSuperAdmin(array $user): bool
    {
        return Role::isSuperAdmin($this->role($user));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isAdmin(array $user): bool
    {
        return Role::isAdmin($this->role($user));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function clinicIdFromToken(array $user): string
    {
        return (string) ($user['clinic_id'] ?? '');
    }

    /**
     * @param array<string, mixed> $user
     */
    public function canAccessClinic(array $user, string $clinicId): bool
    {
        if ($clinicId === '') {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $tokenClinicId = $this->clinicIdFromToken($user);
        if ($tokenClinicId === $clinicId) {
            return true;
        }

        $userId = (string) ($user['user_id'] ?? $user['id'] ?? '');
        if ($userId === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_clinics WHERE user_id::text = :user_id AND clinic_id::text = :clinic_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'clinic_id' => $clinicId]);

        return (bool) $stmt->fetch();
    }

    /**
     * @param array<string, mixed> $user
     * @return list<string>
     */
    public function linkedClinicIds(array $user): array
    {
        if ($this->isSuperAdmin($user)) {
            $stmt = $this->pdo->query('SELECT id::text AS id FROM clinics ORDER BY name ASC');
            $rows = $stmt->fetchAll() ?: [];

            return array_values(array_map(static fn (array $row): string => (string) $row['id'], $rows));
        }

        $clinicIds = [];
        $tokenClinicId = $this->clinicIdFromToken($user);
        if ($tokenClinicId !== '') {
            $clinicIds[$tokenClinicId] = true;
        }

        $userId = (string) ($user['user_id'] ?? $user['id'] ?? '');
        if ($userId !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT clinic_id::text AS clinic_id FROM user_clinics WHERE user_id::text = :user_id'
            );
            $stmt->execute(['user_id' => $userId]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string) ($row['clinic_id'] ?? '');
                if ($id !== '') {
                    $clinicIds[$id] = true;
                }
            }
        }

        return array_keys($clinicIds);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function requireClinicContext(array $user): ?string
    {
        $clinicId = $this->clinicIdFromToken($user);
        if ($clinicId !== '') {
            return $clinicId;
        }

        if ($this->isSuperAdmin($user)) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function assertClinicAccess(array $user, string $clinicId): void
    {
        if (!$this->canAccessClinic($user, $clinicId)) {
            throw new AccessDeniedException('Clinic access denied');
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function assertSuperAdmin(array $user): void
    {
        if (!$this->isSuperAdmin($user)) {
            throw new AccessDeniedException('Super admin required');
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function assertAdminOfClinic(array $user, string $clinicId): void
    {
        if ($this->isSuperAdmin($user)) {
            return;
        }

        if (!$this->isAdmin($user) || !$this->canAccessClinic($user, $clinicId)) {
            throw new AccessDeniedException('Admin clinic access required');
        }
    }
}
