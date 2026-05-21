<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Services;

use App\Application\Stock\LocationPresenter;
use App\Application\Stock\LocationValidator;
use App\Domain\ExitLogs\Exception\ExitLogBusinessRuleException;
use App\Domain\ExitLogs\Exception\ExitLogNotFoundException;
use App\Domain\ExitLogs\ExitLogStatus;
use App\Modules\ExitLogs\DTOs\CreateExitLogDTO;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class ExitLogService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocationValidator $locationValidator
    ) {
    }

    public function create(string $clinicId, string $userId, CreateExitLogDTO $dto): array
    {
        $this->pdo->beginTransaction();
        try {
            $insLog = $this->pdo->prepare(
                'INSERT INTO exit_logs (clinic_id, note, created_by_user_id, status, compartment_id)
                 VALUES (:clinic_id, :note, :created_by_user_id, :status, NULL)
                 RETURNING id, clinic_id, status, note, created_by_user_id, created_at, confirmed_at, cancelled_at, compartment_id'
            );
            $insLog->execute([
                'clinic_id' => $clinicId,
                'note' => $dto->note,
                'created_by_user_id' => $userId,
                'status' => ExitLogStatus::DRAFT,
            ]);
            $header = $insLog->fetch();
            if (!is_array($header)) {
                throw new RuntimeException('Failed to create exit log');
            }
            $exitLogId = (string) $header['id'];

            $insItem = $this->pdo->prepare(
                'INSERT INTO exit_log_items (exit_log_id, product_id, compartment_id, requested_quantity)
                 VALUES (:exit_log_id, :product_id, :compartment_id, :requested_quantity)'
            );

            foreach ($dto->lines as $line) {
                $this->assertProductInClinic($clinicId, $line->productId);
                if ($line->compartmentId !== null) {
                    try {
                        $this->locationValidator->assertCompartmentInClinic($clinicId, $line->compartmentId);
                    } catch (RuntimeException $e) {
                        throw new ExitLogBusinessRuleException($e->getMessage());
                    }
                }
                $insItem->execute([
                    'exit_log_id' => $exitLogId,
                    'product_id' => $line->productId,
                    'compartment_id' => $line->compartmentId,
                    'requested_quantity' => $line->quantity,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getDetail($clinicId, $exitLogId)
            ?? throw new RuntimeException('Exit log not found after create');
    }

    /**
     * @param list<array{item_id:string, quantity:int}> $updates
     */
    public function patchItems(string $clinicId, string $exitLogId, array $updates): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockExitLogHeader($clinicId, $exitLogId);
            if ($row === null) {
                throw new ExitLogNotFoundException('Exit log not found');
            }
            $status = (string) $row['status'];
            if (!ExitLogStatus::isDraft($status)) {
                throw new ExitLogBusinessRuleException('Only draft exit logs can be edited');
            }

            $upd = $this->pdo->prepare(
                'UPDATE exit_log_items
                 SET requested_quantity = :qty, updated_at = NOW()
                 WHERE id::text = :id AND exit_log_id::text = :exit_log_id'
            );

            foreach ($updates as $u) {
                $upd->execute([
                    'qty' => $u['quantity'],
                    'id' => $u['item_id'],
                    'exit_log_id' => $exitLogId,
                ]);
                if ($upd->rowCount() === 0) {
                    throw new ExitLogBusinessRuleException('Invalid item_id for this exit log');
                }
            }

            $this->applyCancelledIfAllQuantitiesZero($exitLogId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getDetail($clinicId, $exitLogId)
            ?? throw new RuntimeException('Exit log not found after update');
    }

    public function confirm(string $clinicId, string $exitLogId): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockExitLogHeader($clinicId, $exitLogId);
            if ($row === null) {
                throw new ExitLogNotFoundException('Exit log not found');
            }
            $status = (string) $row['status'];

            if (ExitLogStatus::isConfirmed($status)) {
                $this->pdo->commit();

                return $this->getDetail($clinicId, $exitLogId)
                    ?? throw new RuntimeException('Exit log not found');
            }

            if (!ExitLogStatus::isDraft($status)) {
                throw new ExitLogBusinessRuleException('Exit log cannot be confirmed in its current state');
            }

            $items = $this->fetchItemsForExit($clinicId, $exitLogId);
            if ($items === []) {
                throw new ExitLogBusinessRuleException('Exit log has no lines');
            }

            $allZero = true;
            foreach ($items as $it) {
                if ((int) $it['requested_quantity'] > 0) {
                    $allZero = false;
                    break;
                }
            }

            if ($allZero) {
                $this->markCancelled($exitLogId);
                $this->pdo->commit();

                return $this->getDetail($clinicId, $exitLogId)
                    ?? throw new RuntimeException('Exit log not found');
            }

            foreach ($items as $it) {
                $qty = (int) $it['requested_quantity'];
                if ($qty <= 0) {
                    continue;
                }
                $productId = (string) $it['product_id'];
                $compartmentId = $it['compartment_id'] ?? null;
                if ($compartmentId !== null && $compartmentId !== '') {
                    $this->decrementInventoryAtCompartment($clinicId, $productId, (string) $compartmentId, $qty);
                } else {
                    $this->decrementInventoryUnlocatedFifo($clinicId, $productId, $qty);
                }
            }

            $conf = $this->pdo->prepare(
                'UPDATE exit_log_items
                 SET confirmed_quantity = requested_quantity, updated_at = NOW()
                 WHERE exit_log_id::text = :exit_log_id'
            );
            $conf->execute(['exit_log_id' => $exitLogId]);

            $this->syncHeaderCompartmentFromItems($clinicId, $exitLogId);

            $upd = $this->pdo->prepare(
                'UPDATE exit_logs
                 SET status = :status, confirmed_at = NOW()
                 WHERE id::text = :id AND clinic_id = :clinic_id'
            );
            $upd->execute([
                'status' => ExitLogStatus::CONFIRMED,
                'id' => $exitLogId,
                'clinic_id' => $clinicId,
            ]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getDetail($clinicId, $exitLogId)
            ?? throw new RuntimeException('Exit log not found after confirm');
    }

    public function cancel(string $clinicId, string $exitLogId): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockExitLogHeader($clinicId, $exitLogId);
            if ($row === null) {
                throw new ExitLogNotFoundException('Exit log not found');
            }
            if (!ExitLogStatus::isDraft((string) $row['status'])) {
                throw new ExitLogBusinessRuleException('Only draft exit logs can be cancelled');
            }
            $this->markCancelled($exitLogId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getDetail($clinicId, $exitLogId)
            ?? throw new RuntimeException('Exit log not found after cancel');
    }

    public function list(string $clinicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT el.id, el.clinic_id, el.status, el.note, el.compartment_id,
                    el.created_by_user_id, el.created_at, el.confirmed_at, el.cancelled_at,
                    (SELECT COUNT(*)::int FROM exit_log_items ei WHERE ei.exit_log_id = el.id) AS items_count
             FROM exit_logs el
             WHERE el.clinic_id = :clinic_id
             ORDER BY el.id DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $exitLogId = (string) $row['id'];
            $compartmentId = $row['compartment_id'] ?? null;
            if ($compartmentId === null || $compartmentId === '') {
                $compartmentId = $this->fetchFirstItemCompartmentId($exitLogId);
            }
            $location = $compartmentId !== null && $compartmentId !== ''
                ? ($this->locationValidator->fetchLocationForCompartment($clinicId, (string) $compartmentId)
                    ?? LocationPresenter::empty())
                : LocationPresenter::empty();

            $out[] = [
                'id' => $exitLogId,
                'clinic_id' => (string) $row['clinic_id'],
                'status' => (string) $row['status'],
                'note' => $row['note'],
                'created_by_user_id' => $row['created_by_user_id'],
                'created_at' => $row['created_at'],
                'confirmed_at' => $row['confirmed_at'],
                'cancelled_at' => $row['cancelled_at'],
                'items_count' => (int) ($row['items_count'] ?? 0),
                'compartment_public_id' => $row['compartment_id'],
                'location' => $location,
            ];
        }

        return $out;
    }

    public function getDetail(string $clinicId, string $exitLogId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT el.id, el.clinic_id, el.status, el.note, el.compartment_id,
                    el.created_by_user_id, el.created_at, el.confirmed_at, el.cancelled_at,
                    u.id AS creator_id, u.email AS creator_email, u.name AS creator_name
             FROM exit_logs el
             LEFT JOIN users u ON u.id = el.created_by_user_id
             WHERE el.id::text = :id AND el.clinic_id = :clinic_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $exitLogId, 'clinic_id' => $clinicId]);
        $header = $stmt->fetch();
        if (!is_array($header)) {
            return null;
        }

        $itemsStmt = $this->pdo->prepare(
            'SELECT
                ei.id,
                ei.product_id,
                ei.compartment_id,
                ei.requested_quantity,
                ei.confirmed_quantity,
                p.name AS product_name,
                p.sku AS product_sku,
                c.code AS compartment_code,
                l.id AS locker_id,
                l.name AS locker_name,
                l.device_id AS locker_device_id,
                ii.quantity AS stock_available
             FROM exit_log_items ei
             INNER JOIN products p
                ON p.id = ei.product_id AND p.clinic_id = :clinic_id
             LEFT JOIN compartments c
                ON c.id = ei.compartment_id AND c.clinic_id = :clinic_id
             LEFT JOIN lockers l
                ON l.id = c.locker_id AND l.clinic_id = :clinic_id
             LEFT JOIN inventory_items ii
                ON ii.clinic_id = :clinic_id
               AND ii.product_id = p.id
               AND (
                    (ei.compartment_id IS NULL AND ii.compartment_id IS NULL)
                    OR ii.compartment_id = ei.compartment_id
               )
             WHERE ei.exit_log_id::text = :exit_log_id
             ORDER BY ei.id ASC'
        );
        $itemsStmt->execute(['clinic_id' => $clinicId, 'exit_log_id' => $exitLogId]);
        $rawItems = $itemsStmt->fetchAll() ?: [];

        $items = [];
        $headerCompartmentId = $header['compartment_id'] ?? null;
        foreach ($rawItems as $it) {
            $location = LocationPresenter::fromJoinRow($it);
            if (($headerCompartmentId === null || $headerCompartmentId === '')
                && $it['compartment_id'] !== null
                && $it['compartment_id'] !== ''
            ) {
                $headerCompartmentId = (string) $it['compartment_id'];
            }

            $items[] = [
                'id' => (string) $it['id'],
                'product' => [
                    'id' => (string) $it['product_id'],
                    'name' => (string) $it['product_name'],
                    'sku' => $it['product_sku'] !== null ? (string) $it['product_sku'] : null,
                    'barcode' => null,
                ],
                'locker' => $location['locker'],
                'compartment' => $location['compartment'],
                'requested_quantity' => (int) $it['requested_quantity'],
                'confirmed_quantity' => $it['confirmed_quantity'] !== null ? (int) $it['confirmed_quantity'] : null,
                'stock_available' => $it['stock_available'] !== null ? (int) $it['stock_available'] : null,
            ];
        }

        $headerLocation = $headerCompartmentId !== null && $headerCompartmentId !== ''
            ? ($this->locationValidator->fetchLocationForCompartment($clinicId, (string) $headerCompartmentId)
                ?? LocationPresenter::empty())
            : LocationPresenter::empty();

        return [
            'exit_log' => [
                'id' => (string) $header['id'],
                'status' => (string) $header['status'],
                'note' => $header['note'],
                'created_by' => [
                    'id' => (string) $header['created_by_user_id'],
                    'public_id' => $header['creator_id'] ?? null,
                    'email' => $header['creator_email'] ?? null,
                    'name' => $header['creator_name'] ?? null,
                ],
                'created_at' => $header['created_at'],
                'confirmed_at' => $header['confirmed_at'],
                'cancelled_at' => $header['cancelled_at'],
                'legacy_sku' => null,
                'legacy_quantity' => null,
                'compartment_public_id' => $header['compartment_id'],
                'location' => $headerLocation,
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockExitLogHeader(string $clinicId, string $exitLogId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, status FROM exit_logs WHERE id::text = :id AND clinic_id = :clinic_id FOR UPDATE'
        );
        $stmt->execute(['id' => $exitLogId, 'clinic_id' => $clinicId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItemsForExit(string $clinicId, string $exitLogId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ei.id, ei.requested_quantity, ei.compartment_id, p.id AS product_id
             FROM exit_log_items ei
             INNER JOIN products p ON p.id = ei.product_id AND p.clinic_id = :clinic_id
             WHERE ei.exit_log_id::text = :id
             ORDER BY ei.id'
        );
        $stmt->execute(['id' => $exitLogId, 'clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function fetchFirstItemCompartmentId(string $exitLogId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT compartment_id
             FROM exit_log_items
             WHERE exit_log_id::text = :id AND compartment_id IS NOT NULL
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute(['id' => $exitLogId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['compartment_id'] === null) {
            return null;
        }

        return (string) $row['compartment_id'];
    }

    private function decrementInventoryAtCompartment(
        string $clinicId,
        string $productId,
        string $compartmentId,
        int $delta
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT id, quantity
             FROM inventory_items
             WHERE clinic_id = :clinic_id
               AND product_id = :product_id
               AND compartment_id = :compartment_id
             LIMIT 1'
        );
        $stmt->execute([
            'clinic_id' => $clinicId,
            'product_id' => $productId,
            'compartment_id' => $compartmentId,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int) $row['quantity'] < $delta) {
            throw new ExitLogBusinessRuleException(
                'Insufficient stock for product at compartment: ' . $productId
            );
        }

        $upd = $this->pdo->prepare(
            'UPDATE inventory_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id'
        );
        $upd->execute([
            'quantity' => (int) $row['quantity'] - $delta,
            'id' => $row['id'],
        ]);
    }

    private function decrementInventoryUnlocatedFifo(string $clinicId, string $productId, int $delta): void
    {
        $remaining = $delta;

        $rowsStmt = $this->pdo->prepare(
            'SELECT id, quantity
             FROM inventory_items
             WHERE clinic_id = :clinic_id
               AND product_id = :product_id
               AND quantity > 0
             ORDER BY
                CASE WHEN compartment_id IS NULL THEN 0 ELSE 1 END,
                updated_at ASC,
                id ASC'
        );
        $rowsStmt->execute([
            'clinic_id' => $clinicId,
            'product_id' => $productId,
        ]);
        $rows = $rowsStmt->fetchAll() ?: [];

        $upd = $this->pdo->prepare(
            'UPDATE inventory_items
             SET quantity = :quantity, updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $current = (int) ($row['quantity'] ?? 0);
            if ($current <= 0) {
                continue;
            }
            $consume = min($current, $remaining);
            $upd->execute([
                'quantity' => $current - $consume,
                'id' => $row['id'],
            ]);
            $remaining -= $consume;
        }

        if ($remaining > 0) {
            throw new ExitLogBusinessRuleException('Insufficient stock for product: ' . $productId);
        }
    }

    private function assertProductInClinic(string $clinicId, string $productId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM products WHERE id = :id AND clinic_id = :clinic_id LIMIT 1'
        );
        $stmt->execute(['id' => $productId, 'clinic_id' => $clinicId]);
        if (!$stmt->fetch()) {
            throw new ExitLogBusinessRuleException('Product not found in clinic');
        }
    }

    private function applyCancelledIfAllQuantitiesZero(string $exitLogId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)::int AS total, COALESCE(SUM(requested_quantity), 0)::int AS sum_qty
             FROM exit_log_items WHERE exit_log_id::text = :id'
        );
        $stmt->execute(['id' => $exitLogId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return;
        }
        $total = (int) $row['total'];
        $sum = (int) $row['sum_qty'];
        if ($total > 0 && $sum === 0) {
            $this->markCancelled($exitLogId);
        }
    }

    private function markCancelled(string $exitLogId): void
    {
        $upd = $this->pdo->prepare(
            'UPDATE exit_logs
             SET status = :status, cancelled_at = NOW()
             WHERE id::text = :id AND status = :draft'
        );
        $upd->execute([
            'status' => ExitLogStatus::CANCELLED,
            'id' => $exitLogId,
            'draft' => ExitLogStatus::DRAFT,
        ]);
    }

    private function syncHeaderCompartmentFromItems(string $clinicId, string $exitLogId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT ei.compartment_id
             FROM exit_log_items ei
             INNER JOIN products p ON p.id = ei.product_id AND p.clinic_id = :clinic_id
             WHERE ei.exit_log_id::text = :eid
               AND ei.confirmed_quantity IS NOT NULL
               AND ei.confirmed_quantity > 0
               AND ei.compartment_id IS NOT NULL
             ORDER BY ei.id ASC
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'eid' => $exitLogId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['compartment_id'] === null || $row['compartment_id'] === '') {
            return;
        }
        $pub = (string) $row['compartment_id'];
        $upd = $this->pdo->prepare(
            'UPDATE exit_logs
             SET compartment_id = :c
             WHERE id::text = :id AND clinic_id = :clinic_id
               AND compartment_id IS NULL'
        );
        $upd->execute(['c' => $pub, 'id' => $exitLogId, 'clinic_id' => $clinicId]);
    }
}
