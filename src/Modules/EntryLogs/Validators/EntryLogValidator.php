<?php

namespace App\Modules\EntryLogs\Validators;

use App\Application\Stock\LocationValidator;
use App\Modules\EntryLogs\DTOs\CreateEntryLogDTO;
use InvalidArgumentException;

final class EntryLogValidator
{
    public function __construct(private readonly LocationValidator $locationValidator)
    {
    }

    public function validateCreate(array $payload): CreateEntryLogDTO
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);
        $name = isset($payload['name']) ? trim((string) $payload['name']) : null;
        $note = isset($payload['note']) ? trim((string) $payload['note']) : null;

        if ($sku === '') {
            throw new InvalidArgumentException('Invalid sku');
        }

        if ($quantity === false || $quantity <= 0) {
            throw new InvalidArgumentException('Invalid quantity');
        }

        $compartmentId = $this->locationValidator->parseOptionalLocation($payload);

        return new CreateEntryLogDTO(
            $sku,
            (int) $quantity,
            $name !== '' ? $name : null,
            $note !== '' ? $note : null,
            $compartmentId
        );
    }
}
