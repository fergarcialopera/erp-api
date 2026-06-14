<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class Role
{
    public const STAFF = 'STAFF';
    public const TECHNICIAN = 'TECHNICIAN';
    public const ADMIN = 'ADMIN';
    public const SUPER_ADMIN = 'SUPER_ADMIN';

    /** @var array<string, int> */
    public const WEIGHTS = [
        self::STAFF => 1,
        self::TECHNICIAN => 2,
        self::ADMIN => 3,
        self::SUPER_ADMIN => 4,
    ];

    public static function normalize(string $role): string
    {
        return strtoupper(trim($role));
    }

    public static function weight(string $role): int
    {
        return self::WEIGHTS[self::normalize($role)] ?? 0;
    }

    public static function isSuperAdmin(string $role): bool
    {
        return self::normalize($role) === self::SUPER_ADMIN;
    }

    public static function isAdmin(string $role): bool
    {
        return self::normalize($role) === self::ADMIN;
    }

    public static function meetsMinimum(string $role, string $minimumRole): bool
    {
        return self::weight($role) >= self::weight($minimumRole);
    }
}
