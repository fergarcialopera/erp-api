<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use App\Application\Support\DisplayName;
use App\Application\Support\PublicUrlBuilder;
use PDO;

final class ClinicService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicUrlBuilder $urls
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

    public function patch(string $clinicId, ?bool $visible, ?string $password, ?string $name): ?array
    {
        $current = $this->pdo->prepare('SELECT id, name, visible, image_path FROM clinics WHERE id::text = :id LIMIT 1');
        $current->execute(['id' => $clinicId]);
        $row = $current->fetch();
        if (!is_array($row)) {
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

        return is_array($updated) ? $this->presentClinic($updated) : null;
    }

    public function updateImagePath(string $clinicId, ?string $imagePath): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE clinics SET image_path = :image_path WHERE id::text = :id
             RETURNING id, name, visible, image_path, password_hash IS NOT NULL AS has_password, created_at'
        );
        $stmt->execute(['id' => $clinicId, 'image_path' => $imagePath]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentClinic($row) : null;
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
