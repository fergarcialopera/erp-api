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
     * @throws InvalidArgumentException when ambiente_id is sent without compartment_id
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

        $ambienteId = null;
        if (array_key_exists('ambiente_id', $payload)) {
            $raw = $payload['ambiente_id'];
            if ($raw !== null && $raw !== '') {
                $ambienteId = trim((string) $raw);
                if ($ambienteId === '') {
                    throw new InvalidArgumentException('Invalid ambiente_id' . $suffix);
                }
            }
        }

        if ($ambienteId !== null && $compartmentId === null) {
            throw new InvalidArgumentException('ambiente_id requires compartment_id' . $suffix);
        }

        if ($compartmentId !== null && $ambienteId !== null) {
            $this->assertCompartmentMatchesAmbiente($compartmentId, $ambienteId);
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

    public function assertCompartmentMatchesAmbiente(string $compartmentId, string $ambienteId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM compartments WHERE id = :compartment_id AND ambiente_id = :ambiente_id LIMIT 1'
        );
        $stmt->execute(['compartment_id' => $compartmentId, 'ambiente_id' => $ambienteId]);
        if (!$stmt->fetch()) {
            throw new InvalidArgumentException('compartment_id does not belong to ambiente_id');
        }
    }

    /**
     * @return array{ambiente: ?array{id: string, name: string, device_id: ?string}, compartment: ?array{id: string, code: string}}|null
     */
    public function fetchLocationForCompartment(string $clinicId, string $compartmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                c.id AS compartment_id,
                c.code AS compartment_code,
                a.id AS ambiente_id,
                a.name AS ambiente_name,
                a.device_id AS ambiente_device_id
             FROM compartments c
             LEFT JOIN ambientes a ON a.id = c.ambiente_id AND a.clinic_id = :clinic_id
             WHERE c.id = :compartment_id AND c.clinic_id = :clinic_id
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'compartment_id' => $compartmentId]);
        $row = $stmt->fetch();

        return is_array($row) ? LocationPresenter::fromJoinRow($row) : null;
    }
}
