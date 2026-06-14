<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Infrastructure\Redis\RedisClient;
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

    protected static function clearAuthAttemptCounters(string ...$userIds): void
    {
        $redis = new RedisClient(
            (string) (getenv('TEST_REDIS_HOST') ?: 'redis'),
            (int) (getenv('TEST_REDIS_PORT') ?: 6379)
        );

        foreach ($userIds as $userId) {
            $redis->del('auth:pin-fail:' . $userId);
            $redis->del('auth:login-fail:' . $userId);
        }
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
                . 'Ejecuta los tests con: composer test:docker (o php bin/run-tests.php). '
                . 'Si la API quedó en modo test, restaura con: composer test:docker:restore. '
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
            // Continuar: puede que la BD no exista aún.
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
        $pinHash = password_hash('1234', PASSWORD_BCRYPT);
        $clinicPasswordHash = password_hash('clinic123', PASSWORD_BCRYPT);

        self::ensureClinic($adminClinic, 'Clinic A', $clinicPasswordHash);
        self::ensureClinic($otherClinic, 'Clinic B', $clinicPasswordHash);

        self::upsertUser('88888888-8888-8888-8888-888888888888', null, 'super@clinic-erp.com', 'SUPER_ADMIN', $passwordHash);
        self::upsertUser('22222222-2222-2222-2222-222222222222', $adminClinic, 'admin@clinic-erp.com', 'ADMIN', $passwordHash, $pinHash);
        self::upsertUser('33333333-3333-3333-3333-333333333333', $adminClinic, 'tech@clinic-erp.com', 'TECHNICIAN', $passwordHash, $pinHash);
        self::upsertUser('44444444-4444-4444-4444-444444444444', $adminClinic, 'staff@clinic-erp.com', 'STAFF', $passwordHash, $pinHash);

        self::upsertUser('55555555-5555-5555-5555-555555555555', $otherClinic, 'admin2@clinic-erp.com', 'ADMIN', $passwordHash, $pinHash);
        self::upsertUser('66666666-6666-6666-6666-666666666666', $otherClinic, 'tech2@clinic-erp.com', 'TECHNICIAN', $passwordHash, $pinHash);
        self::upsertUser('77777777-7777-7777-7777-777777777777', $otherClinic, 'staff2@clinic-erp.com', 'STAFF', $passwordHash, $pinHash);

        self::syncUserClinic('22222222-2222-2222-2222-222222222222', $adminClinic);
        self::syncUserClinic('55555555-5555-5555-5555-555555555555', $otherClinic);
    }

    protected function authHeaderForSuperAdmin(): array
    {
        $login = $this->login('super@clinic-erp.com');
        $this->assertSame(200, $login['status'], 'Login failed for super@clinic-erp.com');
        $this->assertIsArray($login['json']);
        $this->assertArrayHasKey('data', $login['json']);
        $this->assertIsArray($login['json']['data']);
        $this->assertArrayHasKey('access_token', $login['json']['data']);

        return ['Authorization' => 'Bearer ' . $login['json']['data']['access_token']];
    }

    protected function createAmbienteLinkedToClinicA(?string $name = null): string
    {
        $name ??= 'Ambiente-' . bin2hex(random_bytes(2));
        $created = $this->request('POST', '/api/v1/ambientes', ['name' => $name], $this->authHeaderForSuperAdmin());
        $this->assertSame(201, $created['status'], 'Failed to create ambiente');
        $id = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $id);

        $linked = $this->request(
            'POST',
            '/api/v1/clinics/11111111-1111-1111-1111-111111111111/ambientes',
            ['ambiente_id' => $id],
            $this->authHeaderForSuperAdmin()
        );
        $this->assertSame(201, $linked['status'], 'Failed to link ambiente to clinic');

        $visible = $this->request(
            'PATCH',
            '/api/v1/clinic/ambientes/' . $id,
            ['visible' => true],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(200, $visible['status'], 'Failed to make ambiente visible');

        return $id;
    }

    /**
     * @return array{id: string, sku: string}
     */
    protected function createProductVisibleInClinicA(?string $name = null): array
    {
        $name ??= 'Product-' . bin2hex(random_bytes(2));
        $created = $this->request('POST', '/api/v1/products', ['name' => $name], $this->authHeaderForSuperAdmin());
        $this->assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        $sku = (string) ($created['json']['data']['sku'] ?? '');
        $this->assertNotSame('', $id);
        $this->assertNotSame('', $sku);

        $visible = $this->request(
            'PATCH',
            '/api/v1/clinic/products/' . $id,
            ['visible' => true],
            $this->authHeaderFor('admin@clinic-erp.com')
        );
        $this->assertSame(200, $visible['status']);

        return ['id' => $id, 'sku' => $sku];
    }

    protected function insertAmbienteAndZoneForClinic(string $clinicId, string $ambienteName, string $zoneCode): array
    {
        $pdo = self::testPdo();
        $ambienteStmt = $pdo->prepare(
            'INSERT INTO ambientes (name, location, device_id, is_active, created_at, updated_at)
             VALUES (:name, :location, :device_id, TRUE, NOW(), NOW())
             RETURNING id::text AS id, name'
        );
        $ambienteStmt->execute([
            'name' => $ambienteName,
            'location' => 'Planta X',
            'device_id' => 'DEV-' . bin2hex(random_bytes(3)),
        ]);
        $ambiente = $ambienteStmt->fetch();
        $this->assertIsArray($ambiente);
        $ambienteId = (string) ($ambiente['id'] ?? '');

        $pdo->prepare(
            'INSERT INTO clinic_ambientes (clinic_id, ambiente_id, visible)
             VALUES (:clinic_id, :ambiente_id, TRUE)'
        )->execute(['clinic_id' => $clinicId, 'ambiente_id' => $ambienteId]);

        $zoneStmt = $pdo->prepare(
            'INSERT INTO zones (ambiente_id, code, is_active, created_at, updated_at)
             VALUES (:ambiente_id, :code, TRUE, NOW(), NOW())
             RETURNING id::text AS id, code'
        );
        $zoneStmt->execute(['ambiente_id' => $ambienteId, 'code' => $zoneCode]);
        $zone = $zoneStmt->fetch();
        $this->assertIsArray($zone);

        return [
            'ambiente_id' => $ambienteId,
            'ambiente_name' => (string) ($ambiente['name'] ?? ''),
            'zone_id' => (string) ($zone['id'] ?? ''),
            'zone_code' => (string) ($zone['code'] ?? ''),
        ];
    }

    private static function syncUserClinic(string $userId, string $clinicId): void
    {
        $columns = self::tableColumns('user_clinics');
        if ($columns === []) {
            return;
        }

        $check = self::$pdo->prepare(
            'SELECT 1 FROM user_clinics WHERE user_id = :user_id AND clinic_id = :clinic_id LIMIT 1'
        );
        $check->execute(['user_id' => $userId, 'clinic_id' => $clinicId]);
        if ($check->fetch()) {
            return;
        }

        $ins = self::$pdo->prepare(
            'INSERT INTO user_clinics (user_id, clinic_id) VALUES (:user_id, :clinic_id)'
        );
        $ins->execute(['user_id' => $userId, 'clinic_id' => $clinicId]);
    }

    protected static function ensureClinic(string $id, string $name, string $passwordHash): void
    {
        $columns = self::tableColumns('clinics');
        if ($columns === []) {
            return;
        }

        $hasVisible = in_array('visible', $columns, true);
        $hasPassword = in_array('password_hash', $columns, true);

        $check = self::$pdo->prepare('SELECT id FROM clinics WHERE id = :id LIMIT 1');
        $check->execute(['id' => $id]);
        if ($check->fetch()) {
            $sets = ['name = :name'];
            $params = ['id' => $id, 'name' => $name];
            if ($hasVisible) {
                $sets[] = 'visible = TRUE';
            }
            if ($hasPassword) {
                $sets[] = 'password_hash = :password_hash';
                $params['password_hash'] = $passwordHash;
            }
            $upd = self::$pdo->prepare('UPDATE clinics SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $upd->execute($params);

            return;
        }

        if ($hasVisible && $hasPassword) {
            $ins = self::$pdo->prepare(
                'INSERT INTO clinics (id, name, visible, password_hash, created_at)
                 VALUES (:id, :name, TRUE, :password_hash, NOW())'
            );
            $ins->execute(['id' => $id, 'name' => $name, 'password_hash' => $passwordHash]);
        } else {
            $ins = self::$pdo->prepare('INSERT INTO clinics (id, name, created_at) VALUES (:id, :name, NOW())');
            $ins->execute(['id' => $id, 'name' => $name]);
        }
    }

    protected static function upsertUser(
        string $id,
        ?string $clinicId,
        string $email,
        string $role,
        string $hash,
        ?string $pinHash = null
    ): void {
        $columns = self::tableColumns('users');
        $hasIsActive = in_array('is_active', $columns, true);
        $hasUpdatedAt = in_array('updated_at', $columns, true);
        $hasPinHash = in_array('pin_hash', $columns, true);
        $hasLocked = in_array('is_locked', $columns, true);

        $checkStmt = self::$pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkStmt->execute(['email' => $email]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $params = [
                'password_hash' => $hash,
                'role' => $role,
                'email' => $email,
            ];
            if ($clinicId !== null) {
                $sets = ['clinic_id = :clinic_id', 'password_hash = :password_hash', 'role = :role'];
                $params['clinic_id'] = $clinicId;
            } else {
                $sets = ['clinic_id = NULL', 'password_hash = :password_hash', 'role = :role'];
            }
            if ($hasIsActive) {
                $sets[] = 'is_active = TRUE';
            }
            if ($hasPinHash && $pinHash !== null) {
                $sets[] = 'pin_hash = :pin_hash';
                $params['pin_hash'] = $pinHash;
            }
            if ($hasLocked) {
                $sets[] = 'is_locked = FALSE';
                $sets[] = 'locked_at = NULL';
            }
            if ($hasUpdatedAt) {
                $sets[] = 'updated_at = NOW()';
            }

            $stmt = self::$pdo->prepare(
                'UPDATE users SET ' . implode(', ', $sets) . ' WHERE email = :email'
            );
            $stmt->execute($params);

            return;
        }

        $fields = ['id', 'email', 'password_hash', 'role'];
        $values = [':id', ':email', ':password_hash', ':role'];
        $params = [
            'id' => $id,
            'email' => $email,
            'password_hash' => $hash,
            'role' => $role,
        ];
        if ($clinicId !== null) {
            $fields[] = 'clinic_id';
            $values[] = ':clinic_id';
            $params['clinic_id'] = $clinicId;
        }
        if ($hasPinHash && $pinHash !== null) {
            $fields[] = 'pin_hash';
            $values[] = ':pin_hash';
            $params['pin_hash'] = $pinHash;
        }
        if ($hasIsActive) {
            $fields[] = 'is_active';
            $values[] = 'TRUE';
        }
        $fields[] = 'created_at';
        $values[] = 'NOW()';

        $stmt = self::$pdo->prepare(
            'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')'
        );
        $stmt->execute($params);
    }

    /**
     * @param array<string, string> $headers
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
     * @param array<string, string> $headers
     * @return array{status:int,headers:array<string,string>,json:array<string,mixed>|null,raw:string}
     */
    protected function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        return self::staticRequest($method, $path, $body, $headers);
    }

    protected function login(string $email, string $password = 'admin123'): array
    {
        return $this->request('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
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
