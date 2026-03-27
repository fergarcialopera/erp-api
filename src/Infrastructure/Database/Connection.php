<?php

namespace App\Infrastructure\Database;

use PDO;

final class Connection
{
    public static function create(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password
    ): PDO {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
