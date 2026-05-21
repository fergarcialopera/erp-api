#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env') && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$host = $_ENV['DB_HOST'] ?? 'postgres';
$port = $_ENV['DB_PORT'] ?? '5432';
$database = $_ENV['DB_DATABASE'] ?? 'erp';
$username = $_ENV['DB_USERNAME'] ?? 'erp';
$password = $_ENV['DB_PASSWORD'] ?? 'erp';

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$clinicHash = password_hash('clinic123', PASSWORD_BCRYPT);
$pinHash = password_hash('1234', PASSWORD_BCRYPT);

$pdo->exec("UPDATE clinics SET visible = TRUE, password_hash = " . $pdo->quote($clinicHash) . " WHERE password_hash IS NULL");
$pdo->exec('UPDATE users SET pin_hash = ' . $pdo->quote($pinHash) . ' WHERE pin_hash IS NULL');

echo "Credentials backfilled.\n";
