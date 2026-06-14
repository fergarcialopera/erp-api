<?php

declare(strict_types=1);

namespace App\Application\Stock;

final class LocationPresenter
{
    /**
     * @param array<string, mixed> $row Keys: zone_id, zone_code, ambiente_id, ambiente_name, ambiente_device_id
     * @return array{ambiente: ?array{id: string, name: string, device_id: ?string}, zone: ?array{id: string, code: string}}
     */
    public static function fromJoinRow(array $row): array
    {
        $zoneId = $row['zone_id'] ?? null;
        $ambienteId = $row['ambiente_id'] ?? null;

        return [
            'ambiente' => $ambienteId !== null && $ambienteId !== ''
                ? [
                    'id' => (string) $ambienteId,
                    'name' => (string) ($row['ambiente_name'] ?? ''),
                    'device_id' => isset($row['ambiente_device_id']) && $row['ambiente_device_id'] !== null
                        ? (string) $row['ambiente_device_id']
                        : null,
                ]
                : null,
            'zone' => $zoneId !== null && $zoneId !== ''
                ? [
                    'id' => (string) $zoneId,
                    'code' => (string) ($row['zone_code'] ?? ''),
                ]
                : null,
        ];
    }

    /**
     * @return array{ambiente: ?array{id: string, name: string, device_id: ?string}, zone: ?array{id: string, code: string}}
     */
    public static function empty(): array
    {
        return ['ambiente' => null, 'zone' => null];
    }
}
