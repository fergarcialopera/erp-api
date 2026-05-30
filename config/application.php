<?php

declare(strict_types=1);

use App\Infrastructure\Config\Env;
use App\Infrastructure\Database\DatabaseConfig;

/**
 * Whitelist de variables de entorno de la aplicación.
 * Contrato documentado en .env.example.
 *
 * @return array{
 *     app: array{name: string, env: string, debug: bool, url: string},
 *     db: array{host: string, port: string, database: string, username: string, password: string},
 *     urls: array{public: string, frontend: string},
 *     redis: array{host: string, port: int},
 *     mqtt: array{host: string, port: int, username: ?string, password: ?string, client_id: string, disabled: bool},
 *     mail: array{host: string, port: int, from: string, from_name: string},
 *     auth: array{user_ttl: int, clinic_ttl: int},
 *     recovery: array{ttl_minutes: int}
 * }
 */
return [
    'app' => [
        'name' => Env::string('APP_NAME', 'ERP Clinic Stock') ?? 'ERP Clinic Stock',
        'env' => Env::string('APP_ENV', 'local') ?? 'local',
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => Env::trimmed('APP_URL', 'http://localhost:8080'),
    ],
    'db' => DatabaseConfig::fromEnvironment(),
    'urls' => [
        'public' => Env::trimmed('APP_PUBLIC_URL', 'http://localhost:8080'),
        'frontend' => Env::trimmed('FRONTEND_URL', 'http://localhost:3000'),
    ],
    'redis' => [
        'host' => Env::string('REDIS_HOST', 'redis') ?? 'redis',
        'port' => Env::int('REDIS_PORT', 6379),
    ],
    'mqtt' => [
        'host' => Env::trimmed('MQTT_HOST', ''),
        'port' => Env::int('MQTT_PORT', 1883),
        'username' => Env::string('MQTT_USERNAME'),
        'password' => Env::string('MQTT_PASSWORD'),
        'client_id' => Env::trimmed('MQTT_CLIENT_ID', 'erp-backend'),
        'disabled' => Env::bool('MQTT_DISABLED', false),
    ],
    'mail' => [
        'host' => Env::trimmed('MAIL_HOST', ''),
        'port' => Env::int('MAIL_PORT', 1025),
        'from' => Env::string('MAIL_FROM', 'noreply@erp.local') ?? 'noreply@erp.local',
        'from_name' => Env::string('MAIL_FROM_NAME', 'ERP Clinic') ?? 'ERP Clinic',
    ],
    'auth' => [
        'user_ttl' => Env::int('AUTH_USER_TTL', 1800),
        'clinic_ttl' => Env::int('AUTH_CLINIC_TTL', 28800),
    ],
    'recovery' => [
        'ttl_minutes' => Env::int('RECOVERY_TTL_MINUTES', 60),
    ],
];
