<?php

namespace App\Modules\Settings\DTOs;

final class UpsertSettingDTO
{
    public function __construct(
        public readonly string $key,
        public readonly string $value
    ) {
    }
}
