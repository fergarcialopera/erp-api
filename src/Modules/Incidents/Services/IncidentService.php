<?php

namespace App\Modules\Incidents\Services;

use App\Modules\Incidents\DTOs\CreateIncidentDTO;
use PDO;

final class IncidentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $clinicId, string $createdBy, CreateIncidentDTO $dto): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO incidents (clinic_id, title, description, severity, status, created_by)
             VALUES (:clinic_id, :title, :description, :severity, :status, :created_by)
             RETURNING id, clinic_id, title, description, severity, status, created_by, created_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'title' => $dto->title,
            'description' => $dto->description,
            'severity' => $dto->severity,
            'status' => 'OPEN',
            'created_by' => $createdBy,
        ]);

        $incident = $stmt->fetch();
        return is_array($incident) ? $incident : [];
    }

    public function list(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, title, description, severity, status, created_by, created_at
             FROM incidents
             WHERE clinic_id = :clinic_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll() ?: [];
    }
}
