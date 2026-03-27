<?php

namespace App\Modules\Clinic\DTOs;

final class PatchClinicSettingsDTO
{
    public function __construct(public readonly ?int $openLatencyMs)
    {
    }
}

