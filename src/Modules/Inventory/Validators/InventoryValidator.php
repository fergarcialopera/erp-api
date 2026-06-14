<?php

namespace App\Modules\Inventory\Validators;

use App\Application\Stock\LocationValidator;
use App\Modules\Inventory\DTOs\AdjustInventoryLocationDTO;
use App\Modules\Inventory\DTOs\UpsertInventoryItemDTO;
use InvalidArgumentException;

final class InventoryValidator
{
    public function __construct(private readonly LocationValidator $locationValidator)
    {
    }

    /**
     * @return list<AdjustInventoryLocationDTO>
     */
    public function validateAdjustQuantities(array $payload): array
    {
        $rows = $payload['locations'] ?? null;
        if ($rows === null) {
            $rows = [$payload];
        }

        if (!is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('locations must be a non-empty array');
        }

        $out = [];
        $seenZones = [];

        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Each location must be an object');
            }

            $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 0) {
                throw new InvalidArgumentException('Invalid quantity at index ' . (int) $idx);
            }

            $zoneId = $this->locationValidator->parseOptionalLocation(
                $row,
                'index ' . (int) $idx
            );

            $key = $zoneId ?? '';
            if (isset($seenZones[$key])) {
                throw new InvalidArgumentException('Duplicate zone_id in locations');
            }
            $seenZones[$key] = true;

            $out[] = new AdjustInventoryLocationDTO((int) $quantity, $zoneId);
        }

        return $out;
    }

    public function validateUpsert(array $payload): UpsertInventoryItemDTO
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);

        if ($sku === '') {
            throw new InvalidArgumentException('Invalid sku');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name');
        }

        if ($quantity === false || $quantity < 0) {
            throw new InvalidArgumentException('Invalid quantity');
        }

        return new UpsertInventoryItemDTO($sku, $name, (int) $quantity);
    }
}
