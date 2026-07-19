<?php

namespace App\Modules\Incidents\Services;

use App\Application\Audit\AuditActor;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\Incidents\DTOs\CreateIncidentDTO;
use App\Modules\Incidents\DTOs\PatchIncidentDTO;
use PDO;

final class IncidentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditActivityService $audit,
    ) {
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
        if (!is_array($incident)) {
            return [];
        }

        $this->audit->recordAdd(
            'incident',
            (string) $incident['id'],
            $createdBy,
            $clinicId,
            $this->presentIncident($incident),
        );

        return $incident;
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

    public function patch(string $incidentId, PatchIncidentDTO $dto, AuditActor $actor): ?array
    {
        $currentStmt = $this->pdo->prepare(
            'SELECT id, clinic_id, title, description, severity, source, status, created_by_user_id AS created_by, created_at
             FROM incidents WHERE id::text = :id LIMIT 1'
        );
        $currentStmt->execute(['id' => $incidentId]);
        $current = $currentStmt->fetch();
        if (!is_array($current)) {
            return null;
        }

        $before = $this->presentIncident($current);
        $clinicId = (string) $before['clinic_id'];

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

        if (!is_array($row)) {
            return null;
        }

        $after = $this->presentIncident($row);
        $this->audit->recordEdit('incident', $incidentId, $actor->userId, $clinicId, $before, $after);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentIncident(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'clinic_id' => (string) $row['clinic_id'],
            'title' => (string) $row['title'],
            'description' => $row['description'],
            'severity' => (string) $row['severity'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'created_by' => $row['created_by'],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
