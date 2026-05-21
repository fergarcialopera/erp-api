<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use RuntimeException;

final class UserLockedException extends RuntimeException
{
    public function __construct(string $message = 'User account is locked. Contact your administrator.')
    {
        parent::__construct($message);
    }
}
