<?php

namespace App\Modules\Settings\Validators;

use App\Modules\Settings\DTOs\UpsertSettingDTO;
use InvalidArgumentException;

final class SettingValidator
{
    public function validateUpsert(array $payload): UpsertSettingDTO
    {
        $key = trim((string) ($payload['key'] ?? ''));
        $value = trim((string) ($payload['value'] ?? ''));

        if ($key === '') {
            throw new InvalidArgumentException('Invalid key');
        }

        if ($value === '') {
            throw new InvalidArgumentException('Invalid value');
        }

        return new UpsertSettingDTO($key, $value);
    }
}
