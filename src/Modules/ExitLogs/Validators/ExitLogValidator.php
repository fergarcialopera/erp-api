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
            if ($productId === '' || strlen($productId) !== 26) {
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

            $compartmentPublicId = null;
            if (array_key_exists('compartment_id', $row)) {
                $raw = $row['compartment_id'];
                if ($raw !== null && $raw !== '') {
                    $compartmentPublicId = trim((string) $raw);
                    if ($compartmentPublicId === '' || strlen($compartmentPublicId) !== 26) {
                        throw new InvalidArgumentException('Invalid compartment_id at index ' . (int) $idx);
                    }
                }
            }

            $lines[] = new ExitLogLineInputDTO($productId, (int) $quantity, $compartmentPublicId);
        }

        return new CreateExitLogDTO($lines, $note);
    }

    /**
     * @return list<array{item_id: int, quantity: int}>
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
            $itemId = filter_var($row['item_id'] ?? null, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId < 1) {
                throw new InvalidArgumentException('Invalid item_id at index ' . (int) $idx);
            }
            $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 0) {
                throw new InvalidArgumentException('Invalid quantity at index ' . (int) $idx);
            }
            $out[] = ['item_id' => (int) $itemId, 'quantity' => (int) $quantity];
        }

        return $out;
    }
}
