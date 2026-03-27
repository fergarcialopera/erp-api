<?php

namespace App\Infrastructure\Auth;

use App\Infrastructure\Redis\RedisClient;

final class TokenService
{
    private const TOKEN_PREFIX = 'auth:token:';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly int $ttlSeconds = 28800
    ) {
    }

    public function issueToken(array $payload): string
    {
        $token = bin2hex(random_bytes(32));
        $this->redis->setex(self::TOKEN_PREFIX . $token, $this->ttlSeconds, json_encode($payload) ?: '{}');
        return $token;
    }

    public function validateToken(string $token): ?array
    {
        $value = $this->redis->get(self::TOKEN_PREFIX . $token);
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function invalidateToken(string $token): void
    {
        $this->redis->del(self::TOKEN_PREFIX . $token);
    }

    public function getTtlSeconds(): int
    {
        return $this->ttlSeconds;
    }
}
