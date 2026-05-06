<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\DTOs;

final class ExitLogLineInputDTO
{
    public function __construct(
        public readonly string $productPublicId,
        public readonly int $quantity,
        public readonly ?string $compartmentPublicId
    ) {
    }
}
