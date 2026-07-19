<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Application\Audit\AuditActor;
use App\Application\Support\DisplayName;
use App\Application\Support\PublicUrlBuilder;
use App\Domain\Auth\Role;
use App\Infrastructure\Auth\LoginAttemptService;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Users\DTOs\CreateUserDTO;
use App\Modules\Users\DTOs\PatchUserDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class UserService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicUrlBuilder $urls,
        private readonly LoginAttemptService $loginAttempts,
        private readonly AuditActivityService $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $clinicId = null): array
    {
        if ($clinicId !== null && $clinicId !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT DISTINCT u.id, u.clinic_id, u.name, u.email, u.role, u.operational_role_id, u.is_active, u.is_locked, u.image_path, u.created_at, u.updated_at
                 FROM users u
                 LEFT JOIN user_clinics uc ON uc.user_id = u.id
                 WHERE u.clinic_id::text = :clinic_id OR uc.clinic_id::text = :clinic_id
                 ORDER BY u.created_at DESC'
            );
            $stmt->execute(['clinic_id' => $clinicId]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT id, clinic_id, name, email, role, operational_role_id, is_active, is_locked, image_path, created_at, updated_at
                 FROM users
                 WHERE role <> \'SUPER_ADMIN\'
                 ORDER BY created_at DESC'
            );
        }

        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentUser($row), $rows);
    }

    public function get(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, role, operational_role_id, is_active, is_locked, image_path, created_at, updated_at
             FROM users WHERE id::text = :id AND role <> \'SUPER_ADMIN\' LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentUser($row) : null;
    }

    public function create(CreateUserDTO $dto, AuditActor $actor): array
    {
        $existing = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existing->execute(['email' => $dto->email]);
        if ($existing->fetch()) {
            throw new RuntimeException('Email already exists');
        }

        if ($dto->operationalRoleId !== null && !$this->operationalRoleExists($dto->operationalRoleId)) {
            throw new RuntimeException('Operational role not found');
        }

        $id = Uuid::v4()->toRfc4122();
        $hash = password_hash($dto->password, PASSWORD_BCRYPT);
        $pinHash = $dto->pin !== null ? password_hash($dto->pin, PASSWORD_BCRYPT) : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (id, clinic_id, name, email, password_hash, pin_hash, role, operational_role_id, is_active, created_at, updated_at)
                 VALUES (:id, :clinic_id, :name, :email, :password_hash, :pin_hash, :role, :operational_role_id, :is_active, NOW(), NOW())
                 RETURNING id, clinic_id, name, email, role, operational_role_id, is_active, is_locked, image_path, created_at, updated_at'
            );
            $stmt->execute([
                'id' => $id,
                'clinic_id' => $dto->clinicId,
                'name' => $dto->name,
                'email' => $dto->email,
                'password_hash' => $hash,
                'pin_hash' => $pinHash,
                'role' => $dto->role,
                'operational_role_id' => $dto->operationalRoleId,
                'is_active' => $dto->isActive,
            ]);

            if ($dto->role === Role::ADMIN) {
                $this->syncClinicLinks($id, $dto->clinicIds !== [] ? $dto->clinicIds : array_filter([$dto->clinicId]));
            }

            $row = (array) $stmt->fetch();
            $this->pdo->commit();

            $presented = $this->presentUser($row);
            $this->audit->recordAdd('user', $presented['id'], $actor->userId, $actor->clinicId, $presented);

            return $presented;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function patch(string $userId, PatchUserDTO $dto, AuditActor $actor): ?array
    {
        $current = $this->getRaw($userId);
        if ($current === null) {
            return null;
        }

        $before = $this->get($userId);
        if ($before === null) {
            return null;
        }

        $name = $dto->name ?? (string) $current['name'];
        $role = $dto->role ?? (string) $current['role'];
        $isActive = $dto->isActive ?? (bool) $current['is_active'];
        $isLocked = (bool) $current['is_locked'];
        $lockedAt = $current['locked_at'] ?? null;

        if ($dto->unlock === true) {
            $isLocked = false;
            $lockedAt = null;
            $this->loginAttempts->clearAllFailures((string) $current['id']);
        }

        $passwordHash = (string) $current['password_hash'];
        if ($dto->password !== null) {
            $passwordHash = password_hash($dto->password, PASSWORD_BCRYPT);
        }

        $pinHash = $current['pin_hash'] ?? null;
        if ($dto->pin !== null) {
            $pinHash = password_hash($dto->pin, PASSWORD_BCRYPT);
        }

        $clinicId = $current['clinic_id'];
        if ($dto->clinicIds !== null && $role === Role::ADMIN) {
            $clinicId = $dto->clinicIds[0] ?? null;
        }

        $operationalRoleId = $current['operational_role_id'] !== null ? (string) $current['operational_role_id'] : null;
        if ($dto->operationalRoleIdTouched) {
            $operationalRoleId = $dto->operationalRoleId;
            if ($operationalRoleId !== null && !$this->operationalRoleExists($operationalRoleId)) {
                throw new RuntimeException('Operational role not found');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE users
                 SET name = :name, role = :role, clinic_id = :clinic_id, operational_role_id = :operational_role_id,
                     is_active = :is_active, password_hash = :password_hash,
                     pin_hash = :pin_hash, is_locked = :is_locked, locked_at = :locked_at, updated_at = NOW()
                 WHERE id::text = :id
                 RETURNING id, clinic_id, name, email, role, operational_role_id, is_active, is_locked, image_path, created_at, updated_at'
            );
            $stmt->bindValue(':id', $userId);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':role', $role);
            if ($clinicId === null) {
                $stmt->bindValue(':clinic_id', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':clinic_id', $clinicId);
            }
            $stmt->bindValue(':operational_role_id', $operationalRoleId);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':password_hash', $passwordHash);
            if ($pinHash === null) {
                $stmt->bindValue(':pin_hash', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':pin_hash', $pinHash);
            }
            $stmt->bindValue(':is_locked', $isLocked, PDO::PARAM_BOOL);
            if ($lockedAt === null) {
                $stmt->bindValue(':locked_at', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':locked_at', $lockedAt);
            }
            $stmt->execute();
            $row = $stmt->fetch();

            if ($dto->clinicIds !== null && $role === Role::ADMIN) {
                $this->syncClinicLinks($userId, $dto->clinicIds);
            } elseif ($role !== Role::ADMIN) {
                $this->clearClinicLinks($userId);
            }

            $this->pdo->commit();

            if (!is_array($row)) {
                return null;
            }

            $after = $this->presentUser($row);
            $this->audit->recordEdit('user', $userId, $actor->userId, $actor->clinicId, $before, $after);

            return $after;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateImagePath(string $clinicId, string $userId, ?string $imagePath, AuditActor $actor): ?array
    {
        $user = $this->getRaw($userId);
        if ($user === null) {
            return null;
        }

        if ((string) ($user['clinic_id'] ?? '') !== $clinicId && !$this->isLinkedToClinic($userId, $clinicId)) {
            return null;
        }

        $before = $this->get($userId);

        $stmt = $this->pdo->prepare(
            'UPDATE users SET image_path = :image_path, updated_at = NOW()
             WHERE id::text = :id
             RETURNING id, clinic_id, name, email, role, operational_role_id, is_active, is_locked, image_path, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $userId,
            'image_path' => $imagePath,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $after = $this->presentUser($row);
        if ($before !== null) {
            $this->audit->recordEdit('user', $userId, $actor->userId, $clinicId, $before, $after);
        }

        return $after;
    }

    public function softDelete(string $userId, AuditActor $actor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_active = FALSE, updated_at = NOW() WHERE id::text = :id AND role <> \'SUPER_ADMIN\''
        );
        $stmt->execute(['id' => $userId]);

        if ($stmt->rowCount() > 0) {
            $this->audit->recordDelete('user', $userId, $actor->userId, $actor->clinicId);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRaw(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, password_hash, pin_hash, role, operational_role_id, is_active, is_locked, locked_at
             FROM users WHERE id::text = :id AND role <> \'SUPER_ADMIN\' LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string> $clinicIds
     */
    private function syncClinicLinks(string $userId, array $clinicIds): void
    {
        if ($clinicIds === []) {
            throw new RuntimeException('At least one clinic required for ADMIN');
        }

        $delete = $this->pdo->prepare('DELETE FROM user_clinics WHERE user_id::text = :user_id');
        $delete->execute(['user_id' => $userId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO user_clinics (user_id, clinic_id) VALUES (:user_id, :clinic_id)'
        );
        foreach ($clinicIds as $clinicId) {
            $insert->execute(['user_id' => $userId, 'clinic_id' => $clinicId]);
        }
    }

    private function clearClinicLinks(string $userId): void
    {
        $delete = $this->pdo->prepare('DELETE FROM user_clinics WHERE user_id::text = :user_id');
        $delete->execute(['user_id' => $userId]);
    }

    private function isLinkedToClinic(string $userId, string $clinicId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_clinics WHERE user_id::text = :user_id AND clinic_id::text = :clinic_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'clinic_id' => $clinicId]);

        return (bool) $stmt->fetch();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentUser(array $row): array
    {
        $name = (string) ($row['name'] ?? '');
        $imagePath = isset($row['image_path']) ? (string) $row['image_path'] : null;
        $userId = (string) $row['id'];

        return [
            'id' => $userId,
            'clinic_id' => $row['clinic_id'] !== null ? (string) $row['clinic_id'] : null,
            'clinic_ids' => $this->fetchClinicIds($userId, (string) ($row['role'] ?? '')),
            'name' => $name,
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'operational_role_id' => isset($row['operational_role_id']) && $row['operational_role_id'] !== null
                ? (string) $row['operational_role_id']
                : null,
            'operational_role' => $this->fetchOperationalRole(
                isset($row['operational_role_id']) && $row['operational_role_id'] !== null
                    ? (string) $row['operational_role_id']
                    : null
            ),
            'is_active' => (bool) $row['is_active'],
            'is_locked' => (bool) ($row['is_locked'] ?? false),
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'image_url' => $this->urls->asset($imagePath !== '' ? $imagePath : null),
            'display_initial' => DisplayName::initial($name, (string) ($row['email'] ?? '')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array{id: string, name: string, slug: string}|null
     */
    private function fetchOperationalRole(?string $operationalRoleId): ?array
    {
        if ($operationalRoleId === null || $operationalRoleId === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug FROM roles WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $operationalRoleId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
        ];
    }

    private function operationalRoleExists(string $roleId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM roles WHERE id::text = :id AND is_active = TRUE LIMIT 1');
        $stmt->execute(['id' => $roleId]);

        return (bool) $stmt->fetch();
    }

    /**
     * @return list<string>
     */
    private function fetchClinicIds(string $userId, string $role): array
    {
        if ($role !== Role::ADMIN) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT clinic_id::text AS clinic_id FROM user_clinics WHERE user_id::text = :user_id ORDER BY clinic_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_values(array_map(static fn (array $row): string => (string) $row['clinic_id'], $rows));
    }
}
