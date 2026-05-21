<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Infrastructure\Redis\RedisClient;

final class LoginAttemptService
{
    private const PIN_FAIL_PREFIX = 'auth:pin-fail:';
    private const LOGIN_FAIL_PREFIX = 'auth:login-fail:';
    private const MAX_ATTEMPTS = 3;
    private const PIN_FAIL_TTL = 900;
    private const LOGIN_FAIL_TTL = 1800;

    public function __construct(private readonly RedisClient $redis)
    {
    }

    public function recordPinFailure(string $userId): int
    {
        return $this->increment(self::PIN_FAIL_PREFIX . $userId, self::PIN_FAIL_TTL);
    }

    public function recordLoginFailure(string $userId): int
    {
        return $this->increment(self::LOGIN_FAIL_PREFIX . $userId, self::LOGIN_FAIL_TTL);
    }

    public function clearPinFailures(string $userId): void
    {
        $this->redis->del(self::PIN_FAIL_PREFIX . $userId);
    }

    public function clearLoginFailures(string $userId): void
    {
        $this->redis->del(self::LOGIN_FAIL_PREFIX . $userId);
    }

    public function clearAllFailures(string $userId): void
    {
        $this->clearPinFailures($userId);
        $this->clearLoginFailures($userId);
    }

    public function getPinFailures(string $userId): int
    {
        return $this->current(self::PIN_FAIL_PREFIX . $userId);
    }

    public function getLoginFailures(string $userId): int
    {
        return $this->current(self::LOGIN_FAIL_PREFIX . $userId);
    }

    public function isPinLocked(string $userId): bool
    {
        return $this->getPinFailures($userId) >= self::MAX_ATTEMPTS;
    }

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    private function increment(string $key, int $ttl): int
    {
        $current = $this->current($key);
        $next = $current + 1;
        $this->redis->setex($key, $ttl, (string) $next);

        return $next;
    }

    private function current(string $key): int
    {
        $value = $this->redis->get($key);

        return $value !== null ? (int) $value : 0;
    }
}
