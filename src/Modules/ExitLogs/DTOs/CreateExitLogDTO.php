<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\DTOs;

final class CreateExitLogDTO
{
    /**
     * @param list<ExitLogLineInputDTO> $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?string $note
    ) {
    }
}
