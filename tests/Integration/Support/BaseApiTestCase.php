<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class BaseApiTestCase extends TestCase
{
    private const PROBE_USER_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const PROBE_EMAIL = '__phpunit_probe__@test.invalid';
    private const PROBE_PASSWORD = 'probe-only';

    protected static PDO $pdo;
    protected static string $baseUrl;
    protected static bool $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$baseUrl = rtrim((string) getenv('TEST_BASE_URL') ?: 'http://nginx', '/');

        $host = (string) getenv('TEST_DB_HOST') ?: 'postgres';
        $port = (string) getenv('TEST_DB_PORT') ?: '5432';
        $database = (string) getenv('TEST_DB_DATABASE') ?: 'erp_test';
        $username = (string) getenv('TEST_DB_USERNAME') ?: 'erp';
        $password = (string) getenv('TEST_DB_PASSWORD') ?: 'erp';

        self::assertSafeTestDatabase($database);
        self::ensureTestDatabaseExists($host, $port, $database, $username, $password);

        self::$pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        self::ensureSchemaUpToDate();
        self::ensureUsers();
        self::assertApiUsesTestDatabase();
        self::$bootstrapped = true;
    }

    protected static function testPdo(): PDO
    {
        return self::$pdo;
    }

    private static function assertApiUsesTestDatabase(): void
    {
        $hash = password_hash(self::PROBE_PASSWORD, PASSWORD_BCRYPT);
        self::upsertUser(
            self::PROBE_USER_ID,
            '11111111-1111-1111-1111-111111111111',
            self::PROBE_EMAIL,
            'ADMIN',
            $hash
        );

        $res = self::staticRequest('POST', '/api/v1/auth/login', [
            'email' => self::PROBE_EMAIL,
            'password' => self::PROBE_PASSWORD,
        ]);

        if ($res['status'] !== 200) {
            throw new \RuntimeException(
                'La API HTTP no está usando la base de datos de tests (erp_test). '
                . 'Reinicia el contenedor PHP con docker-compose.test.yml antes de ejecutar phpunit. '
                . 'HTTP status del probe: ' . $res['status']
            );
        }
    }

    private static function assertSafeTestDatabase(string $testDatabase): void
    {
        if (!str_ends_with($testDatabase, '_test')) {
            throw new \RuntimeException(
                sprintf('Unsafe test DB: TEST_DB_DATABASE=%s (expected suffix _test)', $testDatabase)
            );
        }

        $appEnv = (string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '');
        if ($appEnv === 'testing') {
            return;
        }

        $prodLikeDb = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');
        if ($prodLikeDb !== '' && $testDatabase === $prodLikeDb && !str_ends_with($prodLikeDb, '_test')) {
            throw new \RuntimeException(
                sprintf(
                    'Unsafe test DB: TEST_DB_DATABASE=%s matches DB_DATABASE=%s (use erp_test and docker-compose.test.yml)',
                    $testDatabase,
                    $prodLikeDb
                )
            );
        }
    }

    private static function ensureTestDatabaseExists(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password
    ): void {
        try {
            $probe = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            $probe = null;
            return;
        } catch (\Throwable) {
            // Continuar: puede que la BD no exista aún (p.ej. volumen persistente ya creado).
        }

        $admin = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $host, $port),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $stmt = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = :db');
        $stmt->execute(['db' => $database]);
        if (!$stmt->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . self::quoteIdentifier($database));
        }
        $admin = null;

        $testDb = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $testDb->exec('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
        $testDb = null;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function ensureSchemaUpToDate(): void
    {
        $root = dirname(__DIR__, 3);
        $migrationsDir = $root . '/database/migrations';
        if (!is_dir($migrationsDir)) {
            return;
        }

        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(32) PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP WITH TIME ZONE NOT NULL
            )'
        );

        $appliedStmt = self::$pdo->query('SELECT filename FROM schema_migrations');
        $appliedRows = $appliedStmt ? $appliedStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $applied = [];
        foreach ($appliedRows as $row) {
            $applied[(string) $row['filename']] = true;
        }

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $filename = basename($file);
            if (isset($applied[$filename])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Cannot read migration file: ' . $filename);
            }

            $version = self::versionFromName($filename);
            self::$pdo->beginTransaction();
            try {
                self::$pdo->exec($sql);
                $ins = self::$pdo->prepare(
                    'INSERT INTO schema_migrations (version, filename, executed_at)
                     VALUES (:version, :filename, NOW())'
                );
                $ins->execute(['version' => $version, 'filename' => $filename]);
                self::$pdo->commit();
            } catch (\Throwable $e) {
                if (self::$pdo->inTransaction()) {
                    self::$pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    private static function versionFromName(string $filename): string
    {
        $parts = explode('_', $filename, 2);
        return preg_replace('/[^0-9]/', '', $parts[0]) ?: (string) time();
    }

    protected static function ensureUsers(): void
    {
        $adminClinic = '11111111-1111-1111-1111-111111111111';
        $otherClinic = '99999999-9999-9999-9999-999999999999';
        $passwordHash = password_hash('admin123', PASSWORD_BCRYPT);

        self::ensureClinic($adminClinic, 'Clinic A');
        self::ensureClinic($otherClinic, 'Clinic B');

        self::upsertUser('22222222-2222-2222-2222-222222222222', $adminClinic, 'admin@clinic.local', 'ADMIN', $passwordHash);
        self::upsertUser('33333333-3333-3333-3333-333333333333', $adminClinic, 'tech@clinic.local', 'TECHNICIAN', $passwordHash);
        self::upsertUser('44444444-4444-4444-4444-444444444444', $adminClinic, 'staff@clinic.local', 'STAFF', $passwordHash);

        self::upsertUser('55555555-5555-5555-5555-555555555555', $otherClinic, 'admin2@clinic.local', 'ADMIN', $passwordHash);
        self::upsertUser('66666666-6666-6666-6666-666666666666', $otherClinic, 'tech2@clinic.local', 'TECHNICIAN', $passwordHash);
        self::upsertUser('77777777-7777-7777-7777-777777777777', $otherClinic, 'staff2@clinic.local', 'STAFF', $passwordHash);
    }

    protected static function ensureClinic(string $id, string $name): void
    {
        $columns = self::tableColumns('clinics');
        if ($columns === []) {
            return;
        }

        $check = self::$pdo->prepare('SELECT id FROM clinics WHERE id = :id LIMIT 1');
        $check->execute(['id' => $id]);
        if ($check->fetch()) {
            $upd = self::$pdo->prepare('UPDATE clinics SET name = :name WHERE id = :id');
            $upd->execute(['id' => $id, 'name' => $name]);
            return;
        }

        $ins = self::$pdo->prepare('INSERT INTO clinics (id, name, created_at) VALUES (:id, :name, NOW())');
        $ins->execute(['id' => $id, 'name' => $name]);
    }

    protected static function upsertUser(string $id, string $clinicId, string $email, string $role, string $hash): void
    {
        $columns = self::tableColumns('users');
        $hasIsActive = in_array('is_active', $columns, true);
        $hasUpdatedAt = in_array('updated_at', $columns, true);

        $checkStmt = self::$pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkStmt->execute(['email' => $email]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $setUpdatedAt = $hasUpdatedAt ? ', updated_at = NOW()' : '';
            if ($hasIsActive) {
                $stmt = self::$pdo->prepare(
                    'UPDATE users
                     SET clinic_id = :clinic_id, password_hash = :password_hash, role = :role, is_active = TRUE'
                    . $setUpdatedAt . '
                     WHERE email = :email'
                );
            } else {
                $stmt = self::$pdo->prepare(
                    'UPDATE users
                     SET clinic_id = :clinic_id, password_hash = :password_hash, role = :role'
                    . $setUpdatedAt . '
                     WHERE email = :email'
                );
            }

            $params = [
                'clinic_id' => $clinicId,
                'password_hash' => $hash,
                'role' => $role,
                'email' => $email,
            ];
            $stmt->execute($params);
            return;
        }

        if ($hasIsActive) {
            $stmt = self::$pdo->prepare(
                'INSERT INTO users (id, clinic_id, email, password_hash, role, is_active, created_at)
                 VALUES (:id, :clinic_id, :email, :password_hash, :role, TRUE, NOW())'
            );
        } else {
            $stmt = self::$pdo->prepare(
                'INSERT INTO users (id, clinic_id, email, password_hash, role, created_at)
                 VALUES (:id, :clinic_id, :email, :password_hash, :role, NOW())'
            );
        }

        $params = [
            'id' => $id,
        ];
        $params += [
            'clinic_id' => $clinicId,
            'email' => $email,
            'password_hash' => $hash,
            'role' => $role,
        ];
        $stmt->execute($params);
    }

    /**
     * @return array{status:int,headers:array<string,string>,json:array<string,mixed>|null,raw:string}
     */
    private static function staticRequest(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $normalizedHeaders = ['Accept: application/json'];

        if ($body !== null) {
            $normalizedHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        foreach ($headers as $key => $value) {
            $normalizedHeaders[] = sprintf('%s: %s', $key, $value);
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $normalizedHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($headerLine);
            },
        ]);

        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);
        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'json' => is_array($json) ? $json : null,
            'raw' => $raw,
        ];
    }

    /**
     * @return array{status:int,headers:array<string,string>,json:array<string,mixed>|null,raw:string}
     */
    protected function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        return self::staticRequest($method, $path, $body, $headers);
    }

    protected function login(string $email, string $password = 'admin123'): array
    {
        $res = $this->request('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        return $res;
    }

    protected function authHeaderFor(string $email): array
    {
        $login = $this->login($email);
        $this->assertSame(200, $login['status'], 'Login failed for ' . $email);
        $this->assertIsArray($login['json']);
        $this->assertArrayHasKey('data', $login['json']);
        $this->assertIsArray($login['json']['data']);
        $this->assertArrayHasKey('access_token', $login['json']['data']);
        return ['Authorization' => 'Bearer ' . $login['json']['data']['access_token']];
    }

    protected function uniqueSku(string $prefix = 'SKU'): string
    {
        return sprintf('%s-%s', $prefix, bin2hex(random_bytes(4)));
    }

    /**
     * @return list<string>
     */
    private static function tableColumns(string $table): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
        );
        $stmt->execute(['table' => $table]);
        $rows = $stmt->fetchAll();
        return array_map(static fn (array $r): string => (string) $r['column_name'], $rows ?: []);
    }
}
