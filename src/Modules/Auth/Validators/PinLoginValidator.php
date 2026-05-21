<?php

declare(strict_types=1);

namespace App\Modules\Auth\Validators;

use InvalidArgumentException;

final class PinLoginValidator
{
    /**
     * @return array{user_id:string,pin:string}
     */
    public function validate(array $payload): array
    {
        $userId = trim((string) ($payload['user_id'] ?? ''));
        $pin = (string) ($payload['pin'] ?? '');

        if ($userId === '') {
            throw new InvalidArgumentException('Invalid user_id');
        }
        if ($pin === '') {
            throw new InvalidArgumentException('Invalid pin');
        }

        return ['user_id' => $userId, 'pin' => $pin];
    }
}
