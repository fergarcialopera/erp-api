#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/load-env.php';

use App\Infrastructure\Database\DatabaseConfig;

$dbConfig = DatabaseConfig::fromEnvironment();

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['database']),
    $dbConfig['username'],
    $dbConfig['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$clinicHash = password_hash('clinic123', PASSWORD_BCRYPT);
$pinHash = password_hash('1234', PASSWORD_BCRYPT);

$pdo->exec('UPDATE clinics SET visible = TRUE, password_hash = ' . $pdo->quote($clinicHash) . ' WHERE password_hash IS NULL');
$pdo->exec('UPDATE users SET pin_hash = ' . $pdo->quote($pinHash) . ' WHERE pin_hash IS NULL');

echo "Credentials backfilled.\n";
