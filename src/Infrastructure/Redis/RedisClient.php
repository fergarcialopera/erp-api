<?php

namespace App\Infrastructure\Redis;

use Predis\Client;

final class RedisClient
{
    private Client $client;

    public function __construct(string $host, int $port)
    {
        $this->client = new Client([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
        ]);
    }

    public function setex(string $key, int $ttlSeconds, string $value): void
    {
        $this->client->setex($key, $ttlSeconds, $value);
    }

    public function get(string $key): ?string
    {
        $value = $this->client->get($key);
        return is_string($value) ? $value : null;
    }

    public function del(string $key): void
    {
        $this->client->del([$key]);
    }
}
