<?php

namespace App\Modules\Clinic\Validators;

use App\Modules\Clinic\DTOs\PatchClinicSettingsDTO;
use InvalidArgumentException;

final class ClinicSettingsValidator
{
    public function validatePatch(array $payload): PatchClinicSettingsDTO
    {
        $openLatency = null;
        if (array_key_exists('open_latency_ms', $payload)) {
            $value = filter_var($payload['open_latency_ms'], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) {
                throw new InvalidArgumentException('Invalid open_latency_ms');
            }
            $openLatency = (int) $value;
        }

        if ($openLatency === null) {
            throw new InvalidArgumentException('No settings provided');
        }

        return new PatchClinicSettingsDTO($openLatency);
    }
}

