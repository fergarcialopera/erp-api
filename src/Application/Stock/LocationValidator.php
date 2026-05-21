<?php

declare(strict_types=1);

namespace App\Application\Stock;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class LocationValidator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @throws InvalidArgumentException when locker_id is sent without compartment_id
     */
    public function parseOptionalLocation(array $payload, string $indexLabel = ''): ?string
    {
        $suffix = $indexLabel !== '' ? ' at ' . $indexLabel : '';

        $compartmentId = null;
        if (array_key_exists('compartment_id', $payload)) {
            $raw = $payload['compartment_id'];
            if ($raw !== null && $raw !== '') {
                $compartmentId = trim((string) $raw);
                if ($compartmentId === '') {
                    throw new InvalidArgumentException('Invalid compartment_id' . $suffix);
                }
            }
        }

        $lockerId = null;
        if (array_key_exists('locker_id', $payload)) {
            $raw = $payload['locker_id'];
            if ($raw !== null && $raw !== '') {
                $lockerId = trim((string) $raw);
                if ($lockerId === '') {
                    throw new InvalidArgumentException('Invalid locker_id' . $suffix);
                }
            }
        }

        if ($lockerId !== null && $compartmentId === null) {
            throw new InvalidArgumentException('locker_id requires compartment_id' . $suffix);
        }

        if ($compartmentId !== null && $lockerId !== null) {
            $this->assertCompartmentMatchesLocker($compartmentId, $lockerId);
        }

        return $compartmentId;
    }

    public function assertCompartmentInClinic(string $clinicId, string $compartmentId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_active FROM compartments WHERE id = :id AND clinic_id = :clinic_id LIMIT 1'
        );
        $stmt->execute(['id' => $compartmentId, 'clinic_id' => $clinicId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Compartment not found in clinic');
        }
        if (!(bool) $row['is_active']) {
            throw new RuntimeException('Compartment is inactive');
        }
    }

    public function assertCompartmentMatchesLocker(string $compartmentId, string $lockerId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM compartments WHERE id = :compartment_id AND locker_id = :locker_id LIMIT 1'
        );
        $stmt->execute(['compartment_id' => $compartmentId, 'locker_id' => $lockerId]);
        if (!$stmt->fetch()) {
            throw new InvalidArgumentException('compartment_id does not belong to locker_id');
        }
    }

    /**
     * @return array{locker: ?array{id: string, name: string, device_id: ?string}, compartment: ?array{id: string, code: string}}|null
     */
    public function fetchLocationForCompartment(string $clinicId, string $compartmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                c.id AS compartment_id,
                c.code AS compartment_code,
                l.id AS locker_id,
                l.name AS locker_name,
                l.device_id AS locker_device_id
             FROM compartments c
             LEFT JOIN lockers l ON l.id = c.locker_id AND l.clinic_id = :clinic_id
             WHERE c.id = :compartment_id AND c.clinic_id = :clinic_id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'compartment_id' => $compartmentId]);
        $row = $stmt->fetch();

        return is_array($row) ? LocationPresenter::fromJoinRow($row) : null;
    }
}
