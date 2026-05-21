<?php

declare(strict_types=1);

namespace App\Modules\Auth\Validators;

use InvalidArgumentException;

final class ClinicLoginValidator
{
    /**
     * @return array{clinic_id:string,password:string}
     */
    public function validate(array $payload): array
    {
        $clinicId = trim((string) ($payload['clinic_id'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($clinicId === '') {
            throw new InvalidArgumentException('Invalid clinic_id');
        }
        if ($password === '') {
            throw new InvalidArgumentException('Invalid password');
        }

        return ['clinic_id' => $clinicId, 'password' => $password];
    }
}
