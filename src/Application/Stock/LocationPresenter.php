<?php

declare(strict_types=1);

namespace App\Application\Stock;

final class LocationPresenter
{
    /**
     * @param array<string, mixed> $row Keys: compartment_id, compartment_code, locker_id, locker_name, locker_device_id
     * @return array{locker: ?array{id: string, name: string, device_id: ?string}, compartment: ?array{id: string, code: string}}
     */
    public static function fromJoinRow(array $row): array
    {
        $compartmentId = $row['compartment_id'] ?? null;
        $lockerId = $row['locker_id'] ?? null;

        return [
            'locker' => $lockerId !== null && $lockerId !== ''
                ? [
                    'id' => (string) $lockerId,
                    'name' => (string) ($row['locker_name'] ?? ''),
                    'device_id' => isset($row['locker_device_id']) && $row['locker_device_id'] !== null
                        ? (string) $row['locker_device_id']
                        : null,
                ]
                : null,
            'compartment' => $compartmentId !== null && $compartmentId !== ''
                ? [
                    'id' => (string) $compartmentId,
                    'code' => (string) ($row['compartment_code'] ?? ''),
                ]
                : null,
        ];
    }

    /**
     * @return array{locker: ?array{id: string, name: string, device_id: ?string}, compartment: ?array{id: string, code: string}}
     */
    public static function empty(): array
    {
        return ['locker' => null, 'compartment' => null];
    }
}
