<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Services;

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
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $clinicId, string $userId, CreateExitLogDTO $dto): array
    {
        $this->pdo->beginTransaction();
        try {
            $insLog = $this->pdo->prepare(
                'INSERT INTO exit_logs (clinic_id, sku, quantity, note, created_by, status, compartment_public_id)
                 VALUES (:clinic_id, NULL, NULL, :note, :created_by, :status, NULL)
                 RETURNING id, clinic_id, status, note, created_by, created_at, confirmed_at, cancelled_at'
            );
            $insLog->execute([
                'clinic_id' => $clinicId,
                'note' => $dto->note,
                'created_by' => $userId,
                'status' => ExitLogStatus::DRAFT,
            ]);
            $header = $insLog->fetch();
            if (!is_array($header)) {
                throw new RuntimeException('Failed to create exit log');
            }
            $exitLogId = (string) $header['id'];

            $insItem = $this->pdo->prepare(
                'INSERT INTO exit_log_items (exit_log_id, product_public_id, compartment_public_id, requested_quantity)
                 VALUES (:exit_log_id, :product_public_id, :compartment_public_id, :requested_quantity)'
            );

            foreach ($dto->lines as $line) {
                $this->assertProductInClinic($clinicId, $line->productPublicId);
                if ($line->compartmentPublicId !== null && $line->compartmentPublicId !== '') {
                    $this->assertCompartmentInClinic($clinicId, $line->compartmentPublicId);
                }
                $insItem->execute([
                    'exit_log_id' => $exitLogId,
                    'product_public_id' => $line->productPublicId,
                    'compartment_public_id' => $line->compartmentPublicId,
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
     * @param list<array{item_id:int, quantity:int}> $updates
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
                 WHERE id = :id AND exit_log_id::text = :exit_log_id'
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

            $skuTotals = $this->aggregateRequestedBySku($items);
            foreach ($skuTotals as $sku => $need) {
                $inv = $this->getInventoryRow($clinicId, $sku);
                if ($inv === null || (int) $inv['quantity'] < $need) {
                    throw new ExitLogBusinessRuleException('Insufficient stock for SKU: ' . $sku);
                }
            }

            foreach ($skuTotals as $sku => $need) {
                $this->decrementInventory($clinicId, $sku, $need);
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
            'SELECT el.id, el.clinic_id, el.status, el.note, el.sku, el.quantity, el.compartment_public_id,
                    el.created_by, el.created_at, el.confirmed_at, el.cancelled_at,
                    (SELECT COUNT(*)::int FROM exit_log_items ei WHERE ei.exit_log_id = el.id) AS items_count
             FROM exit_logs el
             WHERE el.clinic_id = :clinic_id
             ORDER BY el.id DESC'
        );
        $stmt->execute(['clinic_id' => $clinicId]);

        return $stmt->fetchAll() ?: [];
    }

    public function getDetail(string $clinicId, string $exitLogId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT el.id, el.clinic_id, el.status, el.note, el.sku, el.quantity, el.compartment_public_id,
                    el.created_by, el.created_at, el.confirmed_at, el.cancelled_at,
                    u.public_id AS creator_public_id, u.email AS creator_email, u.name AS creator_name
             FROM exit_logs el
             LEFT JOIN users u ON u.id::text = TRIM(el.created_by)
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
                ei.product_public_id,
                ei.compartment_public_id,
                ei.requested_quantity,
                ei.confirmed_quantity,
                p.name AS product_name,
                p.sku AS product_sku,
                c.code AS compartment_code,
                l.public_id AS locker_public_id,
                l.name AS locker_name,
                l.device_id AS locker_device_id,
                ii.quantity AS stock_available
             FROM exit_log_items ei
             INNER JOIN products p
                ON p.public_id = ei.product_public_id AND p.clinic_id = :clinic_id
             LEFT JOIN compartments c
                ON c.public_id = ei.compartment_public_id AND c.clinic_id = :clinic_id
             LEFT JOIN lockers l
                ON l.public_id = c.locker_public_id AND l.clinic_id = :clinic_id
             LEFT JOIN inventory_items ii
                ON ii.clinic_id = :clinic_id AND ii.sku = p.sku
             WHERE ei.exit_log_id::text = :exit_log_id
             ORDER BY ei.id ASC'
        );
        $itemsStmt->execute(['clinic_id' => $clinicId, 'exit_log_id' => $exitLogId]);
        $rawItems = $itemsStmt->fetchAll() ?: [];

        $items = [];
        foreach ($rawItems as $it) {
            $items[] = [
                'id' => (string) $it['id'],
                'product' => [
                    'id' => (string) $it['product_public_id'],
                    'name' => (string) $it['product_name'],
                    'sku' => $it['product_sku'] !== null ? (string) $it['product_sku'] : null,
                    'barcode' => null,
                ],
                'locker' => $it['locker_public_id'] !== null ? [
                    'id' => (string) $it['locker_public_id'],
                    'name' => (string) $it['locker_name'],
                    'device_id' => $it['locker_device_id'] !== null ? (string) $it['locker_device_id'] : null,
                ] : null,
                'compartment' => $it['compartment_public_id'] !== null ? [
                    'id' => (string) $it['compartment_public_id'],
                    'code' => $it['compartment_code'] !== null ? (string) $it['compartment_code'] : '',
                ] : null,
                'requested_quantity' => (int) $it['requested_quantity'],
                'confirmed_quantity' => $it['confirmed_quantity'] !== null ? (int) $it['confirmed_quantity'] : null,
                'stock_available' => $it['stock_available'] !== null ? (int) $it['stock_available'] : null,
            ];
        }

        return [
            'exit_log' => [
                'id' => (string) $header['id'],
                'status' => (string) $header['status'],
                'note' => $header['note'],
                'created_by' => [
                    'id' => (string) $header['created_by'],
                    'public_id' => $header['creator_public_id'] ?? null,
                    'email' => $header['creator_email'] ?? null,
                    'name' => $header['creator_name'] ?? null,
                ],
                'created_at' => $header['created_at'],
                'confirmed_at' => $header['confirmed_at'],
                'cancelled_at' => $header['cancelled_at'],
                'legacy_sku' => $header['sku'],
                'legacy_quantity' => $header['quantity'],
                'compartment_public_id' => $header['compartment_public_id'],
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
            'SELECT ei.id, ei.requested_quantity, p.sku
             FROM exit_log_items ei
             INNER JOIN products p ON p.public_id = ei.product_public_id AND p.clinic_id = :clinic_id
             WHERE ei.exit_log_id::text = :id
             ORDER BY ei.id'
        );
        $stmt->execute(['id' => $exitLogId, 'clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, int> sku => total requested (>0 only)
     */
    private function aggregateRequestedBySku(array $items): array
    {
        $totals = [];
        foreach ($items as $it) {
            $qty = (int) $it['requested_quantity'];
            if ($qty <= 0) {
                continue;
            }
            $sku = (string) $it['sku'];
            $totals[$sku] = ($totals[$sku] ?? 0) + $qty;
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getInventoryRow(string $clinicId, string $sku): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, quantity FROM inventory_items WHERE clinic_id = :clinic_id AND sku = :sku LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'sku' => $sku]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function decrementInventory(string $clinicId, string $sku, int $delta): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inventory_items
             SET quantity = quantity - :delta, updated_at = NOW()
             WHERE clinic_id = :clinic_id AND sku = :sku'
        );
        $stmt->execute([
            'delta' => $delta,
            'clinic_id' => $clinicId,
            'sku' => $sku,
        ]);
    }

    private function assertProductInClinic(string $clinicId, string $productPublicId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM products WHERE public_id = :pid AND clinic_id = :clinic_id LIMIT 1'
        );
        $stmt->execute(['pid' => $productPublicId, 'clinic_id' => $clinicId]);
        if (!$stmt->fetch()) {
            throw new ExitLogBusinessRuleException('Product not found in clinic');
        }
    }

    private function assertCompartmentInClinic(string $clinicId, string $compartmentPublicId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM compartments WHERE public_id = :cid AND clinic_id = :clinic_id LIMIT 1'
        );
        $stmt->execute(['cid' => $compartmentPublicId, 'clinic_id' => $clinicId]);
        if (!$stmt->fetch()) {
            throw new ExitLogBusinessRuleException('Compartment not found in clinic');
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
            'SELECT ei.compartment_public_id
             FROM exit_log_items ei
             INNER JOIN products p ON p.public_id = ei.product_public_id AND p.clinic_id = :clinic_id
             WHERE ei.exit_log_id::text = :eid
               AND ei.confirmed_quantity IS NOT NULL
               AND ei.confirmed_quantity > 0
               AND ei.compartment_public_id IS NOT NULL
             ORDER BY ei.id ASC
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'eid' => $exitLogId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['compartment_public_id'] === null || $row['compartment_public_id'] === '') {
            return;
        }
        $pub = (string) $row['compartment_public_id'];
        $upd = $this->pdo->prepare(
            'UPDATE exit_logs
             SET compartment_public_id = :c
             WHERE id::text = :id AND clinic_id = :clinic_id
               AND (compartment_public_id IS NULL OR TRIM(compartment_public_id) = \'\')'
        );
        $upd->execute(['c' => $pub, 'id' => $exitLogId, 'clinic_id' => $clinicId]);
    }
}
