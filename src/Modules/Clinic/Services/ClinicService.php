<?php

namespace App\Modules\Clinic\Services;

use PDO;

final class ClinicService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getById(string $clinicId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, created_at FROM clinics WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clinicId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}

