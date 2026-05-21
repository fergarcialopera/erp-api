<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Infrastructure\Redis\RedisClient;

final class TokenService
{
    private const LEGACY_USER_PREFIX = 'auth:token:';
    private const USER_PREFIX = 'auth:user:';
    private const CLINIC_PREFIX = 'auth:clinic:';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly int $userTtlSeconds = 1800,
        private readonly int $clinicTtlSeconds = 28800
    ) {
    }

    public function issueUserToken(array $payload): string
    {
        $token = bin2hex(random_bytes(32));
        $payload['type'] = 'user';
        $this->redis->setex(
            self::USER_PREFIX . $token,
            $this->userTtlSeconds,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $token;
    }

    public function issueClinicToken(string $clinicId): string
    {
        $token = bin2hex(random_bytes(32));
        $payload = ['type' => 'clinic', 'clinic_id' => $clinicId];
        $this->redis->setex(
            self::CLINIC_PREFIX . $token,
            $this->clinicTtlSeconds,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $token;
    }

    public function validateUserToken(string $token): ?array
    {
        $value = $this->redis->get(self::USER_PREFIX . $token);
        if ($value === null) {
            $value = $this->redis->get(self::LEGACY_USER_PREFIX . $token);
        }

        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function validateClinicToken(string $token): ?array
    {
        $value = $this->redis->get(self::CLINIC_PREFIX . $token);
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function invalidateUserToken(string $token): void
    {
        $this->redis->del(self::USER_PREFIX . $token);
        $this->redis->del(self::LEGACY_USER_PREFIX . $token);
    }

    public function invalidateClinicToken(string $token): void
    {
        $this->redis->del(self::CLINIC_PREFIX . $token);
    }

    public function getUserTtlSeconds(): int
    {
        return $this->userTtlSeconds;
    }

    public function getClinicTtlSeconds(): int
    {
        return $this->clinicTtlSeconds;
    }
}
