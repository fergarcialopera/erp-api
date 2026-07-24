<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Application\Audit\AuditRequestContext;
use App\Application\Support\Pagination;
use PDO;
use Symfony\Component\Uid\Uuid;

final class AuditLogService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordSuccess(
        string $event,
        AuditRequestContext $context,
        ?string $clinicId = null,
        ?string $userId = null,
    ): void {
        $this->insert($event, true, null, $clinicId, $userId, $context);
    }

    public function recordFailure(
        string $event,
        string $error,
        AuditRequestContext $context,
        ?string $clinicId = null,
        ?string $userId = null,
    ): void {
        $this->insert($event, false, $error, $clinicId, $userId, $context);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    public function list(array $filters, ?string $restrictClinicId): array
    {
        $pagination = Pagination::resolve($filters);
        $conditions = ['1=1'];
        $params = [];

        if ($restrictClinicId !== null && $restrictClinicId !== '') {
            $conditions[] = 'al.clinic_id::text = :restrict_clinic_id';
            $params['restrict_clinic_id'] = $restrictClinicId;
        }

        $event = trim((string) ($filters['event'] ?? ''));
        if ($event !== '') {
            $conditions[] = 'al.event = :event';
            $params['event'] = $event;
        }

        if (array_key_exists('success', $filters) && $filters['success'] !== '' && $filters['success'] !== null) {
            $success = filter_var($filters['success'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($success !== null) {
                $conditions[] = 'al.success = :success';
                $params['success'] = $success;
            }
        }

        $userId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId !== '') {
            $conditions[] = 'al.user_id::text = :user_id';
            $params['user_id'] = $userId;
        }

        $clinicId = trim((string) ($filters['clinic_id'] ?? ''));
        if ($clinicId !== '' && $restrictClinicId === null) {
            $conditions[] = 'al.clinic_id::text = :clinic_id';
            $params['clinic_id'] = $clinicId;
        }

        $where = implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*)::int AS total FROM audit_logs al WHERE ' . $where);
        $this->bindFilterParams($countStmt, $params);
        $countStmt->execute();
        $total = (int) (($countStmt->fetch()['total'] ?? 0));

        $sql = 'SELECT al.id, al.registered_at, al.event, al.success, al.error,
                       al.clinic_id, al.user_id, al.ip_address, al.user_agent, al.request_id,
                       c.name AS clinic_name,
                       u.name AS user_name, u.email AS user_email, u.role AS user_role
                FROM audit_logs al
                LEFT JOIN clinics c ON c.id = al.clinic_id
                LEFT JOIN users u ON u.id = al.user_id
                WHERE ' . $where . '
                ORDER BY al.registered_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $this->bindFilterParams($stmt, $params);
        $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return [
            'items' => array_map(fn (array $row): array => $this->presentListItem($row), $rows),
            'meta' => [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
            ],
        ];
    }

    public function getById(string $id, ?string $restrictClinicId): ?array
    {
        $sql = 'SELECT al.id, al.registered_at, al.event, al.success, al.error,
                       al.clinic_id, al.user_id, al.ip_address, al.user_agent, al.request_id,
                       c.name AS clinic_name,
                       u.name AS user_name, u.email AS user_email, u.role AS user_role
                FROM audit_logs al
                LEFT JOIN clinics c ON c.id = al.clinic_id
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.id::text = :id';
        $params = ['id' => $id];

        if ($restrictClinicId !== null && $restrictClinicId !== '') {
            $sql .= ' AND al.clinic_id::text = :restrict_clinic_id';
            $params['restrict_clinic_id'] = $restrictClinicId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentListItem($row) : null;
    }

    private function insert(
        string $event,
        bool $success,
        ?string $error,
        ?string $clinicId,
        ?string $userId,
        AuditRequestContext $context,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (id, registered_at, event, success, error, clinic_id, user_id, ip_address, user_agent, request_id)
             VALUES (:id, NOW(), :event, :success, :error, :clinic_id, :user_id, :ip_address, :user_agent, :request_id)'
        );

        $stmt->bindValue(':id', Uuid::v4()->toRfc4122());
        $stmt->bindValue(':event', $event);
        $stmt->bindValue(':success', $success, PDO::PARAM_BOOL);
        if ($error === null) {
            $stmt->bindValue(':error', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':error', $error);
        }
        if ($clinicId === null || $clinicId === '') {
            $stmt->bindValue(':clinic_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':clinic_id', $clinicId);
        }
        if ($userId === null || $userId === '') {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':user_id', $userId);
        }
        if ($context->ipAddress === null) {
            $stmt->bindValue(':ip_address', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':ip_address', $context->ipAddress);
        }
        if ($context->userAgent === null) {
            $stmt->bindValue(':user_agent', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':user_agent', $context->userAgent);
        }
        if ($context->requestId === null) {
            $stmt->bindValue(':request_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':request_id', $context->requestId);
        }

        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentListItem(array $row): array
    {
        $clinicId = $row['clinic_id'] !== null ? (string) $row['clinic_id'] : null;
        $userId = $row['user_id'] !== null ? (string) $row['user_id'] : null;

        return [
            'id' => (string) $row['id'],
            'registered_at' => (string) $row['registered_at'],
            'event' => (string) $row['event'],
            'success' => (bool) $row['success'],
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'clinic' => $clinicId !== null ? [
                'id' => $clinicId,
                'name' => (string) ($row['clinic_name'] ?? ''),
            ] : null,
            'user' => $userId !== null ? [
                'id' => $userId,
                'name' => (string) ($row['user_name'] ?? ''),
                'email' => (string) ($row['user_email'] ?? ''),
                'role' => (string) ($row['user_role'] ?? ''),
            ] : null,
            'ip_address' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            'user_agent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            'request_id' => $row['request_id'] !== null ? (string) $row['request_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindFilterParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_BOOL);
                continue;
            }
            $stmt->bindValue(':' . $key, $value);
        }
    }
}
