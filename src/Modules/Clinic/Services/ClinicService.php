<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use App\Application\Audit\AuditActor;
use App\Application\Support\DisplayName;
use App\Application\Support\PublicUrlBuilder;
use App\Modules\Audit\Services\AuditActivityService;
use PDO;

final class ClinicService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicUrlBuilder $urls,
        private readonly AuditActivityService $audit,
    ) {
    }

    public function getById(string $clinicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, visible, image_path, password_hash IS NOT NULL AS has_password, created_at
             FROM clinics WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $clinicId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentClinic($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, visible, image_path, password_hash IS NOT NULL AS has_password, created_at
             FROM clinics ORDER BY name ASC'
        );
        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->presentClinic($row), $rows);
    }

    public function create(string $name, string $password, AuditActor $actor): array
    {
        $id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO clinics (id, name, visible, password_hash, created_at)
             VALUES (:id, :name, TRUE, :password_hash, NOW())
             RETURNING id, name, visible, image_path, password_hash IS NOT NULL AS has_password, created_at'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'password_hash' => $hash,
        ]);

        $presented = $this->presentClinic((array) $stmt->fetch());
        $this->audit->recordAdd('clinic', $presented['id'], $actor->userId, $presented['id'], $presented);

        return $presented;
    }

    public function patch(string $clinicId, ?bool $visible, ?string $password, ?string $name, AuditActor $actor): ?array
    {
        $current = $this->pdo->prepare('SELECT id, name, visible, image_path FROM clinics WHERE id::text = :id LIMIT 1');
        $current->execute(['id' => $clinicId]);
        $row = $current->fetch();
        if (!is_array($row)) {
            return null;
        }

        $before = $this->getById($clinicId);
        if ($before === null) {
            return null;
        }

        $nextVisible = $visible ?? (bool) $row['visible'];
        $nextName = $name ?? (string) $row['name'];

        if ($password !== null) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare(
                'UPDATE clinics SET visible = :visible, name = :name, password_hash = :password_hash WHERE id::text = :id
                 RETURNING id, name, visible, image_path, password_hash IS NOT NULL AS has_password, created_at'
            );
            $stmt->execute([
                'id' => $clinicId,
                'visible' => $nextVisible,
                'name' => $nextName,
                'password_hash' => $hash,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE clinics SET visible = :visible, name = :name WHERE id::text = :id
                 RETURNING id, name, visible, image_path, password_hash IS NOT NULL AS has_password, created_at'
            );
            $stmt->execute([
                'id' => $clinicId,
                'visible' => $nextVisible,
                'name' => $nextName,
            ]);
        }

        $updated = $stmt->fetch();

        if (!is_array($updated)) {
            return null;
        }

        $after = $this->presentClinic($updated);
        $this->audit->recordEdit('clinic', $clinicId, $actor->userId, $clinicId, $before, $after);

        return $after;
    }

    public function updateImagePath(string $clinicId, ?string $imagePath, AuditActor $actor): ?array
    {
        $before = $this->getById($clinicId);

        $stmt = $this->pdo->prepare(
            'UPDATE clinics SET image_path = :image_path WHERE id::text = :id
             RETURNING id, name, visible, image_path, password_hash IS NOT NULL AS has_password, created_at'
        );
        $stmt->execute(['id' => $clinicId, 'image_path' => $imagePath]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $after = $this->presentClinic($row);
        if ($before !== null) {
            $this->audit->recordEdit('clinic', $clinicId, $actor->userId, $clinicId, $before, $after);
        }

        return $after;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentClinic(array $row): array
    {
        $name = (string) ($row['name'] ?? '');
        $imagePath = isset($row['image_path']) ? (string) $row['image_path'] : null;

        return [
            'id' => (string) $row['id'],
            'name' => $name,
            'visible' => (bool) ($row['visible'] ?? true),
            'has_password' => (bool) ($row['has_password'] ?? false),
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'image_url' => $this->urls->asset($imagePath !== '' ? $imagePath : null),
            'display_initial' => DisplayName::initial($name),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
