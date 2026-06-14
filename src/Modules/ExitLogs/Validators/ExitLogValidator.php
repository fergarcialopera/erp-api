<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Validators;

use App\Application\Stock\LocationValidator;
use App\Modules\ExitLogs\DTOs\CreateExitLogDTO;
use App\Modules\ExitLogs\DTOs\ExitLogLineInputDTO;
use InvalidArgumentException;

final class ExitLogValidator
{
    public function __construct(private readonly LocationValidator $locationValidator)
    {
    }

    public function validateCreate(array $payload): CreateExitLogDTO
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new InvalidArgumentException('items must be a non-empty array');
        }

        $note = isset($payload['note']) ? trim((string) $payload['note']) : null;
        $note = $note !== '' ? $note : null;

        $lines = [];
        $seenProducts = [];
        foreach ($items as $idx => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Each item must be an object');
            }
            $productId = trim((string) ($row['product_id'] ?? ''));
            if ($productId === '') {
                throw new InvalidArgumentException('Invalid product_id at index ' . (int) $idx);
            }
            if (isset($seenProducts[$productId])) {
                throw new InvalidArgumentException('Duplicate product_id in items');
            }
            $seenProducts[$productId] = true;

            $hasLocations = array_key_exists('locations', $row);
            $hasQuantity = array_key_exists('quantity', $row);

            if ($hasLocations && $hasQuantity) {
                throw new InvalidArgumentException(
                    'Cannot send both locations and quantity on the same item at index ' . (int) $idx
                );
            }

            if ($hasLocations) {
                foreach ($this->parseProductLocations($productId, $row['locations'], (int) $idx) as $line) {
                    $lines[] = $line;
                }
            } else {
                $lines[] = $this->parseLegacyItem($productId, $row, (int) $idx);
            }
        }

        return new CreateExitLogDTO($lines, $note);
    }

    /**
     * @return list<ExitLogLineInputDTO>
     */
    private function parseProductLocations(string $productId, mixed $locations, int $itemIdx): array
    {
        if (!is_array($locations) || $locations === []) {
            throw new InvalidArgumentException('locations must be a non-empty array at item index ' . $itemIdx);
        }

        $lines = [];
        $seenZones = [];

        foreach ($locations as $locIdx => $loc) {
            if (!is_array($loc)) {
                throw new InvalidArgumentException(
                    'Each location must be an object at item index ' . $itemIdx . ' location index ' . (int) $locIdx
                );
            }

            $quantity = filter_var($loc['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity <= 0) {
                throw new InvalidArgumentException(
                    'Invalid quantity at item index ' . $itemIdx . ' location index ' . (int) $locIdx
                );
            }

            $label = 'item ' . $itemIdx . ' location ' . (int) $locIdx;
            $zoneId = $this->locationValidator->parseOptionalLocation($loc, $label);
            if ($zoneId === null) {
                throw new InvalidArgumentException('zone_id is required at ' . $label);
            }

            if (isset($seenZones[$zoneId])) {
                throw new InvalidArgumentException(
                    'Duplicate zone_id in locations at item index ' . $itemIdx
                );
            }
            $seenZones[$zoneId] = true;

            $lines[] = new ExitLogLineInputDTO($productId, (int) $quantity, $zoneId);
        }

        return $lines;
    }

    private function parseLegacyItem(string $productId, array $row, int $idx): ExitLogLineInputDTO
    {
        $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity <= 0) {
            throw new InvalidArgumentException('Invalid quantity at index ' . $idx);
        }

        $zoneId = $this->locationValidator->parseOptionalLocation(
            $row,
            'index ' . $idx
        );

        return new ExitLogLineInputDTO($productId, (int) $quantity, $zoneId);
    }

    /**
     * @return list<array{item_id: string, quantity: int}>
     */
    public function validatePatchItems(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new InvalidArgumentException('items must be a non-empty array');
        }

        $out = [];
        foreach ($items as $idx => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Each item must be an object');
            }
            $itemId = trim((string) ($row['item_id'] ?? ''));
            if ($itemId === '') {
                throw new InvalidArgumentException('Invalid item_id at index ' . (int) $idx);
            }
            $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 0) {
                throw new InvalidArgumentException('Invalid quantity at index ' . (int) $idx);
            }
            $out[] = ['item_id' => $itemId, 'quantity' => (int) $quantity];
        }

        return $out;
    }
}
