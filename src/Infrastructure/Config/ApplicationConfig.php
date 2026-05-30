<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class ApplicationConfig
{
    /**
     * @param array{name: string, env: string, debug: bool, url: string} $app
     * @param array{host: string, port: string, database: string, username: string, password: string} $database
     * @param array{public: string, frontend: string} $urls
     * @param array{host: string, port: int} $redis
     * @param array{host: string, port: int, username: ?string, password: ?string, client_id: string, disabled: bool} $mqtt
     * @param array{host: string, port: int, from: string, from_name: string} $mail
     * @param array{user_ttl: int, clinic_ttl: int} $auth
     * @param array{ttl_minutes: int} $recovery
     */
    private function __construct(
        private readonly array $app,
        private readonly array $database,
        private readonly array $urls,
        private readonly array $redis,
        private readonly array $mqtt,
        private readonly array $mail,
        private readonly array $auth,
        private readonly array $recovery,
    ) {
    }

    public static function load(): self
    {
        /** @var array{
         *     app: array{name: string, env: string, debug: bool, url: string},
         *     db: array{host: string, port: string, database: string, username: string, password: string},
         *     urls: array{public: string, frontend: string},
         *     redis: array{host: string, port: int},
         *     mqtt: array{host: string, port: int, username: ?string, password: ?string, client_id: string, disabled: bool},
         *     mail: array{host: string, port: int, from: string, from_name: string},
         *     auth: array{user_ttl: int, clinic_ttl: int},
         *     recovery: array{ttl_minutes: int}
         * } $config
         */
        $config = require dirname(__DIR__, 3) . '/config/application.php';

        return new self(
            $config['app'],
            $config['db'],
            $config['urls'],
            $config['redis'],
            $config['mqtt'],
            $config['mail'],
            $config['auth'],
            $config['recovery'],
        );
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public function database(): array
    {
        return $this->database;
    }

    public function publicBaseUrl(): string
    {
        return rtrim($this->urls['public'], '/');
    }

    public function frontendBaseUrl(): string
    {
        return rtrim($this->urls['frontend'], '/');
    }

    public function authUserTtl(): int
    {
        return $this->auth['user_ttl'];
    }

    public function authClinicTtl(): int
    {
        return $this->auth['clinic_ttl'];
    }

    public function recoveryTtlMinutes(): int
    {
        return $this->recovery['ttl_minutes'];
    }

    public function mailHost(): string
    {
        return $this->mail['host'];
    }

    public function mailPort(): int
    {
        return $this->mail['port'];
    }

    public function mailFrom(): string
    {
        return $this->mail['from'];
    }

    public function mailFromName(): string
    {
        return $this->mail['from_name'];
    }

    public function mqttDisabled(): bool
    {
        return $this->mqtt['disabled'];
    }

    public function mqttHost(): string
    {
        return $this->mqtt['host'];
    }

    /** Config plana para infraestructura que ya consume App\Infrastructure\Config\Config. */
    public function infrastructure(): Config
    {
        return new Config([
            'db.host' => $this->database['host'],
            'db.port' => $this->database['port'],
            'db.database' => $this->database['database'],
            'db.username' => $this->database['username'],
            'db.password' => $this->database['password'],
            'redis.host' => $this->redis['host'],
            'redis.port' => $this->redis['port'],
            'mqtt.host' => $this->mqtt['host'],
            'mqtt.port' => $this->mqtt['port'],
            'mqtt.username' => $this->mqtt['username'],
            'mqtt.password' => $this->mqtt['password'],
            'mqtt.client_id' => $this->mqtt['client_id'],
            'mqtt.disabled' => $this->mqtt['disabled'],
        ]);
    }
}
