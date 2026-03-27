#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env') && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

final class DbMigrator
{
    private PDO $pdo;
    private string $root;

    public function __construct()
    {
        $this->root = dirname(__DIR__);
        $this->pdo = $this->connect();
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;
        if ($command === null || in_array($command, ['-h', '--help'], true)) {
            $this->printHelp();
            return 0;
        }

        return match ($command) {
            'migrate' => $this->migrate(),
            'seed' => $this->seed(),
            'status' => $this->status(),
            default => $this->unknownCommand((string) $command),
        };
    }

    private function migrate(): int
    {
        $this->ensureMigrationTable();
        $files = $this->sqlFiles($this->root . '/database/migrations');
        $applied = $this->applied('schema_migrations');
        $pending = array_values(array_filter($files, fn (string $f): bool => !isset($applied[basename($f)])));

        if ($pending === []) {
            echo "No pending migrations.\n";
            return 0;
        }

        foreach ($pending as $file) {
            $name = basename($file);
            $version = $this->versionFromName($name);
            $sql = file_get_contents($file);
            if ($sql === false) {
                fwrite(STDERR, "Cannot read migration file: {$name}\n");
                return 1;
            }

            echo "Applying migration: {$name}\n";
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, filename, executed_at) VALUES (:version, :filename, NOW())'
                );
                $stmt->execute([
                    'version' => $version,
                    'filename' => $name,
                ]);
                $this->pdo->commit();
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                fwrite(STDERR, "Migration failed ({$name}): {$e->getMessage()}\n");
                return 1;
            }
        }

        echo "Migrations applied successfully.\n";
        return 0;
    }

    private function seed(): int
    {
        $this->ensureSeederTable();
        $files = $this->sqlFiles($this->root . '/database/seeders');
        $applied = $this->applied('schema_seeders');
        $pending = array_values(array_filter($files, fn (string $f): bool => !isset($applied[basename($f)])));

        if ($pending === []) {
            echo "No pending seeders.\n";
            return 0;
        }

        foreach ($pending as $file) {
            $name = basename($file);
            $sql = file_get_contents($file);
            if ($sql === false) {
                fwrite(STDERR, "Cannot read seeder file: {$name}\n");
                return 1;
            }

            echo "Applying seeder: {$name}\n";
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO schema_seeders (filename, executed_at) VALUES (:filename, NOW())'
                );
                $stmt->execute(['filename' => $name]);
                $this->pdo->commit();
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                fwrite(STDERR, "Seeder failed ({$name}): {$e->getMessage()}\n");
                return 1;
            }
        }

        echo "Seeders applied successfully.\n";
        return 0;
    }

    private function status(): int
    {
        $this->ensureMigrationTable();
        $this->ensureSeederTable();

        echo "Migrations:\n";
        $this->printStatusTable(
            $this->sqlFiles($this->root . '/database/migrations'),
            $this->applied('schema_migrations')
        );

        echo "\nSeeders:\n";
        $this->printStatusTable(
            $this->sqlFiles($this->root . '/database/seeders'),
            $this->applied('schema_seeders')
        );

        return 0;
    }

    private function printStatusTable(array $files, array $applied): void
    {
        if ($files === []) {
            echo "  (none)\n";
            return;
        }

        foreach ($files as $file) {
            $name = basename($file);
            $flag = isset($applied[$name]) ? 'YES' : 'NO ';
            echo "  [{$flag}] {$name}\n";
        }
    }

    private function sqlFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function applied(string $table): array
    {
        $stmt = $this->pdo->query("SELECT filename FROM {$table}");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['filename']] = true;
        }
        return $map;
    }

    private function versionFromName(string $filename): string
    {
        $parts = explode('_', $filename, 2);
        return preg_replace('/[^0-9]/', '', $parts[0]) ?: (string) time();
    }

    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(32) PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP WITH TIME ZONE NOT NULL
            )'
        );
    }

    private function ensureSeederTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_seeders (
                filename VARCHAR(255) PRIMARY KEY,
                executed_at TIMESTAMP WITH TIME ZONE NOT NULL
            )'
        );
    }

    private function connect(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? 'postgres';
        $port = $_ENV['DB_PORT'] ?? '5432';
        $db = $_ENV['DB_DATABASE'] ?? 'erp';
        $user = $_ENV['DB_USERNAME'] ?? 'erp';
        $pass = $_ENV['DB_PASSWORD'] ?? 'erp';

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $db),
            (string) $user,
            (string) $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, "Unknown command: {$command}\n");
        $this->printHelp();
        return 1;
    }

    private function printHelp(): void
    {
        echo "Usage: php bin/db.php <command>\n";
        echo "Commands:\n";
        echo "  migrate   Run pending SQL migrations\n";
        echo "  seed      Run pending SQL seeders\n";
        echo "  status    Show migration/seeder status\n";
    }
}

$app = new DbMigrator();
exit($app->run($argv));

