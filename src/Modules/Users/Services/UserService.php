<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\DTOs\CreateUserDTO;
use App\Modules\Users\DTOs\PatchUserDTO;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class UserService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, role, is_active, created_at, updated_at
             FROM users
             WHERE clinic_id = :clinic_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll() ?: [];
    }

    public function get(string $clinicId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, name, email, role, is_active, created_at, updated_at
             FROM users
             WHERE clinic_id = :clinic_id AND id::text = :id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
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

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, clinic_id, name, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (:id, :clinic_id, :name, :email, :password_hash, :role, :is_active, NOW(), NOW())
             RETURNING id, clinic_id, name, email, role, is_active, created_at, updated_at'
        );
        $stmt->execute([
            'id' => $id,
            'clinic_id' => $clinicId,
            'name' => $dto->name,
            'email' => $dto->email,
            'password_hash' => $hash,
            'role' => $dto->role,
            'is_active' => $dto->isActive,
        ]);

        return (array) $stmt->fetch();
    }

    public function patch(string $clinicId, string $userId, PatchUserDTO $dto): ?array
    {
        $current = $this->get($clinicId, $userId);
        if ($current === null) {
            return null;
        }

        $name = $dto->name ?? (string) $current['name'];
        $role = $dto->role ?? (string) $current['role'];
        $isActive = $dto->isActive ?? (bool) $current['is_active'];

        if ($dto->password !== null) {
            $hash = password_hash($dto->password, PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare(
                'UPDATE users
                 SET name = :name, role = :role, is_active = :is_active, password_hash = :password_hash, updated_at = NOW()
                 WHERE clinic_id = :clinic_id AND id::text = :id
                 RETURNING id, clinic_id, name, email, role, is_active, created_at, updated_at'
            );
            $stmt->execute([
                'clinic_id' => $clinicId,
                'id' => $userId,
                'name' => $name,
                'role' => $role,
                'is_active' => $isActive,
                'password_hash' => $hash,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE users
                 SET name = :name, role = :role, is_active = :is_active, updated_at = NOW()
                 WHERE clinic_id = :clinic_id AND id::text = :id
                 RETURNING id, clinic_id, name, email, role, is_active, created_at, updated_at'
            );
            $stmt->execute([
                'clinic_id' => $clinicId,
                'id' => $userId,
                'name' => $name,
                'role' => $role,
                'is_active' => $isActive,
            ]);
        }

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
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
}

