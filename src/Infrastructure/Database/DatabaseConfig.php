<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use RuntimeException;

final class DatabaseConfig
{
    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public static function fromEnvironment(): array
    {
        $appEnv = (string) (self::env('APP_ENV') ?? '');

        $database = (string) (self::env('DB_DATABASE') ?? 'erp');
        if ($appEnv === 'testing') {
            $database = (string) (self::env('TEST_DB_DATABASE') ?? 'erp_test');
            if ($database === '' || !str_ends_with($database, '_test')) {
                throw new RuntimeException(
                    'APP_ENV=testing requires TEST_DB_DATABASE with suffix _test (got: ' . $database . ')'
                );
            }
        }

        return [
            'host' => (string) (self::env('DB_HOST') ?? 'postgres'),
            'port' => (string) (self::env('DB_PORT') ?? '5432'),
            'database' => $database,
            'username' => (string) (self::env('DB_USERNAME') ?? 'erp'),
            'password' => (string) (self::env('DB_PASSWORD') ?? 'erp'),
        ];
    }

    /**
     * Alinea variables DB_* con TEST_* para tests de integración (CLI o in-process).
     */
    public static function applyIntegrationTestEnvironment(): void
    {
        $map = [
            'APP_ENV' => 'testing',
            'DB_HOST' => self::env('TEST_DB_HOST') ?? 'postgres',
            'DB_PORT' => self::env('TEST_DB_PORT') ?? '5432',
            'DB_DATABASE' => self::env('TEST_DB_DATABASE') ?? 'erp_test',
            'DB_USERNAME' => self::env('TEST_DB_USERNAME') ?? 'erp',
            'DB_PASSWORD' => self::env('TEST_DB_PASSWORD') ?? 'erp',
        ];

        foreach ($map as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    private static function env(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
            return $value === false ? null : (string) $value;
        }

        $value = getenv($key);
        if ($value === false) {
            return null;
        }

        return (string) $value;
    }
}
