<?php

namespace App\Modules\Incidents\Services;

use App\Modules\Incidents\DTOs\CreateIncidentDTO;
use App\Modules\Incidents\DTOs\PatchIncidentDTO;
use PDO;

final class IncidentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $clinicId, string $createdBy, CreateIncidentDTO $dto): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO incidents (clinic_id, title, description, severity, source, status, created_by_user_id)
             VALUES (:clinic_id, :title, :description, :severity, :source, :status, :created_by_user_id)
             RETURNING id, clinic_id, title, description, severity, source, status, created_by_user_id AS created_by, created_at'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'title' => $dto->title,
            'description' => $dto->description,
            'severity' => $dto->severity,
            'source' => $dto->source,
            'status' => 'OPEN',
            'created_by_user_id' => $createdBy,
        ]);

        $incident = $stmt->fetch();
        return is_array($incident) ? $incident : [];
    }

    public function list(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, title, description, severity, source, status, created_by_user_id AS created_by, created_at
             FROM incidents
             WHERE clinic_id = :clinic_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll() ?: [];
    }

    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, clinic_id, title, description, severity, source, status, created_by_user_id AS created_by, created_at
             FROM incidents
             ORDER BY created_at DESC'
        );

        return $stmt->fetchAll() ?: [];
    }

    public function patch(string $incidentId, PatchIncidentDTO $dto): ?array
    {
        $currentStmt = $this->pdo->prepare(
            'SELECT title, description, severity, status FROM incidents WHERE id::text = :id LIMIT 1'
        );
        $currentStmt->execute(['id' => $incidentId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE incidents
             SET title = :title, description = :description, severity = :severity, status = :status
             WHERE id::text = :id
             RETURNING id, clinic_id, title, description, severity, source, status, created_by_user_id AS created_by, created_at'
        );
        $stmt->execute([
            'id' => $incidentId,
            'title' => $dto->title ?? $current['title'],
            'description' => $dto->description ?? $current['description'],
            'severity' => $dto->severity ?? $current['severity'],
            'status' => $dto->status ?? $current['status'],
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
