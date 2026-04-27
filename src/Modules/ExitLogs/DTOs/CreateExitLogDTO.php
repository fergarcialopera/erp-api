<?php

namespace App\Modules\ExitLogs\DTOs;

final class CreateExitLogDTO
{
    public function __construct(
        public readonly string $sku,
        public readonly int $quantity,
        public readonly ?string $note,
        public readonly ?string $compartmentPublicId
    ) {
    }
}
