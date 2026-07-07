<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Application\Audit\AuditActivitySanitizer;
use App\Application\Support\Pagination;
use PDO;
use Symfony\Component\Uid\Uuid;

final class AuditActivityService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditActivitySanitizer $sanitizer,
    ) {
    }

    /**
     * @param array<string, mixed> $after
     */
    public function recordAdd(
        string $entity,
        string $entityId,
        string $userId,
        ?string $clinicId,
        array $after,
    ): void {
        $this->insert('add', $entity, $entityId, $userId, $clinicId, [
            'after' => $this->sanitizer->sanitize($after),
        ]);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function recordEdit(
        string $entity,
        string $entityId,
        string $userId,
        ?string $clinicId,
        array $before,
        array $after,
    ): void {
        $this->insert('edit', $entity, $entityId, $userId, $clinicId, [
            'before' => $this->sanitizer->sanitize($before),
            'after' => $this->sanitizer->sanitize($after),
        ]);
    }

    public function recordDelete(
        string $entity,
        string $entityId,
        string $userId,
        ?string $clinicId,
    ): void {
        $this->insert('delete', $entity, $entityId, $userId, $clinicId, null);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    public function list(array $filters, ?string $restrictClinicId, bool $includeData = false): array
    {
        $pagination = Pagination::resolve($filters);
        $conditions = ['1=1'];
        $params = [];

        if ($restrictClinicId !== null && $restrictClinicId !== '') {
            $conditions[] = 'aa.clinic_id::text = :restrict_clinic_id';
            $params['restrict_clinic_id'] = $restrictClinicId;
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $conditions[] = 'aa.type = :type';
            $params['type'] = $type;
        }

        $entity = trim((string) ($filters['entity'] ?? ''));
        if ($entity !== '') {
            $conditions[] = 'aa.entity = :entity';
            $params['entity'] = $entity;
        }

        $entityId = trim((string) ($filters['entity_id'] ?? ''));
        if ($entityId !== '') {
            $conditions[] = 'aa.entity_id::text = :entity_id';
            $params['entity_id'] = $entityId;
        }

        $userId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId !== '') {
            $conditions[] = 'aa.user_id::text = :user_id';
            $params['user_id'] = $userId;
        }

        $clinicId = trim((string) ($filters['clinic_id'] ?? ''));
        if ($clinicId !== '' && $restrictClinicId === null) {
            $conditions[] = 'aa.clinic_id::text = :clinic_id';
            $params['clinic_id'] = $clinicId;
        }

        $where = implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*)::int AS total FROM audit_activity aa WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int) (($countStmt->fetch()['total'] ?? 0));

        $sql = 'SELECT aa.id, aa.registered_at, aa.type, aa.entity, aa.entity_id,
                       aa.user_id, aa.clinic_id' . ($includeData ? ', aa.data' : '') . ',
                       c.name AS clinic_name,
                       u.name AS user_name, u.email AS user_email, u.role AS user_role
                FROM audit_activity aa
                LEFT JOIN clinics c ON c.id = aa.clinic_id
                LEFT JOIN users u ON u.id = aa.user_id
                WHERE ' . $where . '
                ORDER BY aa.registered_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return [
            'items' => array_map(
                fn (array $row): array => $this->presentListItem($row, $includeData),
                $rows
            ),
            'meta' => [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
            ],
        ];
    }

    public function getById(string $id, ?string $restrictClinicId): ?array
    {
        $sql = 'SELECT aa.id, aa.registered_at, aa.type, aa.entity, aa.entity_id,
                       aa.user_id, aa.clinic_id, aa.data,
                       c.name AS clinic_name,
                       u.name AS user_name, u.email AS user_email, u.role AS user_role
                FROM audit_activity aa
                LEFT JOIN clinics c ON c.id = aa.clinic_id
                LEFT JOIN users u ON u.id = aa.user_id
                WHERE aa.id::text = :id';
        $params = ['id' => $id];

        if ($restrictClinicId !== null && $restrictClinicId !== '') {
            $sql .= ' AND aa.clinic_id::text = :restrict_clinic_id';
            $params['restrict_clinic_id'] = $restrictClinicId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $this->presentListItem($row, true) : null;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function insert(
        string $type,
        string $entity,
        string $entityId,
        string $userId,
        ?string $clinicId,
        ?array $data,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_activity (id, registered_at, type, entity, entity_id, user_id, clinic_id, data)
             VALUES (:id, NOW(), :type, :entity, :entity_id, :user_id, :clinic_id, CAST(:data AS jsonb))'
        );

        $json = $data !== null
            ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt->bindValue(':id', Uuid::v4()->toRfc4122());
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':entity', $entity);
        $stmt->bindValue(':entity_id', $entityId);
        $stmt->bindValue(':user_id', $userId);
        if ($clinicId === null || $clinicId === '') {
            $stmt->bindValue(':clinic_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':clinic_id', $clinicId);
        }
        if ($json === null || $json === false) {
            $stmt->bindValue(':data', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':data', $json);
        }

        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentListItem(array $row, bool $includeData): array
    {
        $clinicId = $row['clinic_id'] !== null ? (string) $row['clinic_id'] : null;
        $userId = (string) $row['user_id'];

        $item = [
            'id' => (string) $row['id'],
            'registered_at' => (string) $row['registered_at'],
            'type' => (string) $row['type'],
            'entity' => (string) $row['entity'],
            'entity_id' => (string) $row['entity_id'],
            'user_id' => $userId,
            'clinic_id' => $clinicId,
            'user' => [
                'id' => $userId,
                'name' => (string) ($row['user_name'] ?? ''),
                'email' => (string) ($row['user_email'] ?? ''),
                'role' => (string) ($row['user_role'] ?? ''),
            ],
            'clinic' => $clinicId !== null ? [
                'id' => $clinicId,
                'name' => (string) ($row['clinic_name'] ?? ''),
            ] : null,
        ];

        if ($includeData && array_key_exists('data', $row)) {
            $decoded = is_string($row['data'])
                ? json_decode($row['data'], true)
                : $row['data'];
            $item['data'] = is_array($decoded) ? $decoded : null;
        }

        return $item;
    }
}
