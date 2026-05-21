<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Application\Support\DisplayName;
use App\Application\Support\PublicUrlBuilder;
use App\Infrastructure\Auth\LoginAttemptService;
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
        private readonly LoginAttemptService $loginAttempts
    ) {
    }

    public function list(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, role, is_active, is_locked, image_path, created_at, updated_at
             FROM users
             WHERE clinic_id = :clinic_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentUser($row), $rows);
    }

    public function get(string $clinicId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, role, is_active, is_locked, image_path, created_at, updated_at
             FROM users
             WHERE clinic_id = :clinic_id AND id::text = :id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentUser($row) : null;
    }

    public function create(string $clinicId, CreateUserDTO $dto): array
    {
        $existing = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existing->execute(['email' => $dto->email]);
        if ($existing->fetch()) {
            throw new RuntimeException('Email already exists');
        }

        $id = Uuid::v4()->toRfc4122();
        $hash = password_hash($dto->password, PASSWORD_BCRYPT);
        $pinHash = $dto->pin !== null ? password_hash($dto->pin, PASSWORD_BCRYPT) : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, clinic_id, name, email, password_hash, pin_hash, role, is_active, created_at, updated_at)
             VALUES (:id, :clinic_id, :name, :email, :password_hash, :pin_hash, :role, :is_active, NOW(), NOW())
             RETURNING id, clinic_id, name, email, role, is_active, is_locked, image_path, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'clinic_id' => $clinicId,
            'name' => $dto->name,
            'email' => $dto->email,
            'password_hash' => $hash,
            'pin_hash' => $pinHash,
            'role' => $dto->role,
            'is_active' => $dto->isActive,
        ]);

        return $this->presentUser((array) $stmt->fetch());
    }

    public function patch(string $clinicId, string $userId, PatchUserDTO $dto): ?array
    {
        $current = $this->getRaw($clinicId, $userId);
        if ($current === null) {
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

        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET name = :name, role = :role, is_active = :is_active, password_hash = :password_hash,
                 pin_hash = :pin_hash, is_locked = :is_locked, locked_at = :locked_at, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id
             RETURNING id, clinic_id, name, email, role, is_active, is_locked, image_path, created_at, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'id' => $userId,
            'name' => $name,
            'role' => $role,
            'is_active' => $isActive,
            'password_hash' => $passwordHash,
            'pin_hash' => $pinHash,
            'is_locked' => $isLocked,
            'locked_at' => $lockedAt,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $this->presentUser($row) : null;
    }

    public function updateImagePath(string $clinicId, string $userId, ?string $imagePath): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET image_path = :image_path, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id
             RETURNING id, clinic_id, name, email, role, is_active, is_locked, image_path, created_at, updated_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'id' => $userId,
            'image_path' => $imagePath,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentUser($row) : null;
    }

    public function softDelete(string $clinicId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_active = FALSE, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND id::text = :id'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRaw(string $clinicId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, password_hash, pin_hash, role, is_active, is_locked, locked_at
             FROM users WHERE clinic_id = :clinic_id AND id::text = :id LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentUser(array $row): array
    {
        $name = (string) ($row['name'] ?? '');
        $imagePath = isset($row['image_path']) ? (string) $row['image_path'] : null;

        return [
            'id' => (string) $row['id'],
            'clinic_id' => (string) $row['clinic_id'],
            'name' => $name,
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'is_active' => (bool) $row['is_active'],
            'is_locked' => (bool) ($row['is_locked'] ?? false),
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'image_url' => $this->urls->asset($imagePath !== '' ? $imagePath : null),
            'display_initial' => DisplayName::initial($name, (string) ($row['email'] ?? '')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
