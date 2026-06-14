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
     * @throws InvalidArgumentException when ambiente_id is sent without zone_id
     */
    public function parseOptionalLocation(array $payload, string $indexLabel = ''): ?string
    {
        $suffix = $indexLabel !== '' ? ' at ' . $indexLabel : '';

        $zoneId = null;
        if (array_key_exists('zone_id', $payload)) {
            $raw = $payload['zone_id'];
            if ($raw !== null && $raw !== '') {
                $zoneId = trim((string) $raw);
                if ($zoneId === '') {
                    throw new InvalidArgumentException('Invalid zone_id' . $suffix);
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

        if ($ambienteId !== null && $zoneId === null) {
            throw new InvalidArgumentException('ambiente_id requires zone_id' . $suffix);
        }

        if ($zoneId !== null && $ambienteId !== null) {
            $this->assertZoneMatchesAmbiente($zoneId, $ambienteId);
        }

        return $zoneId;
    }

    public function assertZoneInClinic(string $clinicId, string $zoneId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT z.is_active
             FROM zones z
             INNER JOIN clinic_ambientes ca ON ca.ambiente_id = z.ambiente_id AND ca.clinic_id = :clinic_id
             INNER JOIN ambientes a ON a.id = z.ambiente_id
             WHERE z.id = :id AND ca.visible = TRUE AND a.is_active = TRUE
             LIMIT 1'
        );
        $stmt->execute(['id' => $zoneId, 'clinic_id' => $clinicId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Zone not found in clinic');
        }
        if (!(bool) $row['is_active']) {
            throw new RuntimeException('Zone is inactive');
        }
    }

    public function assertZoneMatchesAmbiente(string $zoneId, string $ambienteId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM zones WHERE id = :zone_id AND ambiente_id = :ambiente_id LIMIT 1'
        );
        $stmt->execute(['zone_id' => $zoneId, 'ambiente_id' => $ambienteId]);
        if (!$stmt->fetch()) {
            throw new InvalidArgumentException('zone_id does not belong to ambiente_id');
        }
    }

    /**
     * @return array{ambiente: ?array{id: string, name: string, device_id: ?string}, zone: ?array{id: string, code: string}}|null
     */
    public function fetchLocationForZone(string $clinicId, string $zoneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                c.id AS zone_id,
                c.code AS zone_code,
                a.id AS ambiente_id,
                a.name AS ambiente_name,
                a.device_id AS ambiente_device_id
             FROM zones c
             INNER JOIN ambientes a ON a.id = c.ambiente_id
             INNER JOIN clinic_ambientes ca ON ca.ambiente_id = a.id AND ca.clinic_id = :clinic_id
             WHERE c.id = :zone_id AND ca.visible = TRUE AND a.is_active = TRUE
             LIMIT 1'
        );
        $stmt->execute(['clinic_id' => $clinicId, 'zone_id' => $zoneId]);
        $row = $stmt->fetch();

        return is_array($row) ? LocationPresenter::fromJoinRow($row) : null;
    }
}
