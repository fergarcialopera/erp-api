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

        $compartmentPublicId = null;
        if (array_key_exists('compartment_public_id', $payload)) {
            $raw = $payload['compartment_public_id'];
            if ($raw !== null && $raw !== '') {
                $compartmentPublicId = trim((string) $raw);
                if ($compartmentPublicId === '' || strlen($compartmentPublicId) !== 26) {
                    throw new InvalidArgumentException('Invalid compartment_public_id');
                }
            }
        }

        return new CreateExitLogDTO($sku, (int) $quantity, $note !== '' ? $note : null, $compartmentPublicId);
    }
}
