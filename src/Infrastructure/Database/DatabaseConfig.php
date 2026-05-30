<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Config\Env;
use RuntimeException;

final class DatabaseConfig
{
    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public static function fromEnvironment(): array
    {
        $appEnv = Env::string('APP_ENV', 'local') ?? 'local';

        $database = Env::string('DB_DATABASE', 'erp') ?? 'erp';
        if ($appEnv === 'testing') {
            $database = Env::string('TEST_DB_DATABASE', 'erp_test') ?? 'erp_test';
            if ($database === '' || !str_ends_with($database, '_test')) {
                throw new RuntimeException(
                    'APP_ENV=testing requires TEST_DB_DATABASE with suffix _test (got: ' . $database . ')'
                );
            }
        }

        return [
            'host' => Env::string('DB_HOST', 'postgres') ?? 'postgres',
            'port' => Env::string('DB_PORT', '5432') ?? '5432',
            'database' => $database,
            'username' => Env::string('DB_USERNAME', 'erp') ?? 'erp',
            'password' => Env::string('DB_PASSWORD', 'erp') ?? 'erp',
        ];
    }

    /**
     * Alinea variables DB_* con TEST_* para tests de integración (CLI o in-process).
     */
    public static function applyIntegrationTestEnvironment(): void
    {
        $map = [
            'APP_ENV' => 'testing',
            'DB_HOST' => Env::string('TEST_DB_HOST', 'postgres') ?? 'postgres',
            'DB_PORT' => Env::string('TEST_DB_PORT', '5432') ?? '5432',
            'DB_DATABASE' => Env::string('TEST_DB_DATABASE', 'erp_test') ?? 'erp_test',
            'DB_USERNAME' => Env::string('TEST_DB_USERNAME', 'erp') ?? 'erp',
            'DB_PASSWORD' => Env::string('TEST_DB_PASSWORD', 'erp') ?? 'erp',
        ];

        foreach ($map as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
