<?php

namespace App\Modules\EntryLogs\DTOs;

final class CreateEntryLogDTO
{
    public function __construct(
        public readonly string $sku,
        public readonly int $quantity,
        public readonly ?string $name,
        public readonly ?string $note,
        public readonly ?string $zoneId
    ) {
    }
}
