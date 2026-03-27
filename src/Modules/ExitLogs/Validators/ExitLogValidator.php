<?php

namespace App\Modules\ExitLogs\Validators;

use App\Modules\ExitLogs\DTOs\CreateExitLogDTO;
use InvalidArgumentException;

final class ExitLogValidator
{
    public function validateCreate(array $payload): CreateExitLogDTO
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);
        $note = isset($payload['note']) ? trim((string) $payload['note']) : null;

        if ($sku === '') {
            throw new InvalidArgumentException('Invalid sku');
        }

        if ($quantity === false || $quantity <= 0) {
            throw new InvalidArgumentException('Invalid quantity');
        }

        return new CreateExitLogDTO($sku, (int) $quantity, $note !== '' ? $note : null);
    }
}
