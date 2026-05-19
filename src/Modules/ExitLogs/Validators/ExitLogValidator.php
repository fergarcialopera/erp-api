<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Validators;

use App\Modules\ExitLogs\DTOs\CreateExitLogDTO;
use App\Modules\ExitLogs\DTOs\ExitLogLineInputDTO;
use InvalidArgumentException;

final class ExitLogValidator
{
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

            $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity <= 0) {
                throw new InvalidArgumentException('Invalid quantity at index ' . (int) $idx);
            }

            $compartmentId = null;
            if (array_key_exists('compartment_id', $row)) {
                $raw = $row['compartment_id'];
                if ($raw !== null && $raw !== '') {
                    $compartmentId = trim((string) $raw);
                    if ($compartmentId === '') {
                        throw new InvalidArgumentException('Invalid compartment_id at index ' . (int) $idx);
                    }
                }
            }

            $lines[] = new ExitLogLineInputDTO($productId, (int) $quantity, $compartmentId);
        }

        return new CreateExitLogDTO($lines, $note);
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
