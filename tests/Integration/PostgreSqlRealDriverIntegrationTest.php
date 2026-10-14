<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration;

use KaziSTM\LaravelScopedBackup\Database\Drivers\PostgreSqlDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Tests\Integration\Concerns\InteractsWithRealDatabases;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use PDO;
use Throwable;

class PostgreSqlRealDriverIntegrationTest extends TestCase
{
    use InteractsWithRealDatabases;

    protected ?PDO $adminPdo = null;

    protected string $sourceDb = '';

    protected string $targetDb = '';

    protected array $dbConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = env('DB_PGSQL_HOST');
        $port = (int) (env('DB_PGSQL_PORT') ?: 5432);
        $username = (string) (env('DB_PGSQL_USERNAME') ?: 'postgres');
        $password = env('DB_PGSQL_PASSWORD') !== null ? (string) env('DB_PGSQL_PASSWORD') : '';
        $adminDb = (string) (env('DB_PGSQL_DATABASE') ?: 'postgres');

        if (empty($host)) {
            $this->handleServiceUnavailable('pgsql', null, $port);
            return;
        }

        try {
            $this->adminPdo = new PDO(
                "pgsql:host={$host};port={$port};dbname={$adminDb};user={$username};password={$password}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        } catch (Throwable $e) {
            $this->handleServiceUnavailable('pgsql', $host, $port, $e);
            return;
        }

        $uniqueSuffix = bin2hex(random_bytes(4));
        $this->sourceDb = "test_scoped_pkg_pg_src_{$uniqueSuffix}";
        $this->targetDb = "test_scoped_pkg_pg_dst_{$uniqueSuffix}";

        $this->dbConfig = [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ];

        $this->cleanupDatabases();
    }

    protected function tearDown(): void
    {
        $this->cleanupDatabases();
        parent::tearDown();
    }

    protected function cleanupDatabases(): void
    {
        if ($this->adminPdo && ! empty($this->sourceDb) && ! empty($this->targetDb)) {
            try {
                foreach ([$this->sourceDb, $this->targetDb] as $db) {
                    $this->adminPdo->exec("
                        SELECT pg_terminate_backend(pid) 
                        FROM pg_stat_activity 
                        WHERE datname = '{$db}' AND pid <> pg_backend_pid()
                    ");
                    $this->adminPdo->exec("DROP DATABASE IF EXISTS \"{$db}\"");
                }
            } catch (Throwable) {
                // Ignore cleanup errors on tearDown
            }
        }
    }

    public function test_postgresql_driver_full_lifecycle_against_real_instance(): void
    {
        if (! $this->adminPdo) {
            $this->markTestSkipped('PostgreSQL admin connection not initialized.');
        }

        $driver = new PostgreSqlDriver;
        $avail = $driver->checkAvailability();
        $this->assertTrue($avail['available'], 'pg_dump must be available: '.($avail['message'] ?? ''));

        $dumpFile = sys_get_temp_dir().'/pg_integration_dump_'.uniqid('', true).'.dump';

        try {
            // 1. Create dedicated source and target databases
            $this->adminPdo->exec("CREATE DATABASE \"{$this->sourceDb}\"");
            $this->adminPdo->exec("CREATE DATABASE \"{$this->targetDb}\"");

            // 2. Connect to source database and build schema with tables, sequences, triggers, views
            $srcPdo = new PDO(
                "pgsql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->sourceDb}",
                $this->dbConfig['username'],
                $this->dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $srcPdo->exec('
                CREATE TABLE companies (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    metadata JSONB,
                    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE projects (
                    id SERIAL PRIMARY KEY,
                    company_id INT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                    title VARCHAR(255) NOT NULL,
                    budget NUMERIC(12, 2) NOT NULL DEFAULT 0.00
                );

                CREATE VIEW v_company_summary AS
                SELECT c.id, c.name, COUNT(p.id) as project_count, COALESCE(SUM(p.budget), 0.00) as total_budget
                FROM companies c
                LEFT JOIN projects p ON p.company_id = c.id
                GROUP BY c.id, c.name;

                CREATE OR REPLACE FUNCTION notify_company_change() RETURNS TRIGGER AS $$
                BEGIN
                    NEW.name = TRIM(NEW.name);
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_company_clean_name
                BEFORE INSERT OR UPDATE ON companies
                FOR EACH ROW EXECUTE FUNCTION notify_company_change();
            ');

            // Insert sample records including unicode and JSON
            $stmt = $srcPdo->prepare('INSERT INTO companies (name, metadata) VALUES (?, ?)');
            $stmt->execute(['Acme Global 🚀', json_encode(['tier' => 'enterprise', 'features' => ['sso', 'audit']])]);
            $companyId = (int) $srcPdo->lastInsertId();

            $stmtProj = $srcPdo->prepare('INSERT INTO projects (company_id, title, budget) VALUES (?, ?, ?)');
            $stmtProj->execute([$companyId, 'Project Apollo', 150000.50]);
            $stmtProj->execute([$companyId, 'Project Mercury', 75000.00]);

            // 3. Perform dump
            $dumpConfig = array_merge($this->dbConfig, ['database' => $this->sourceDb]);
            $dumpResult = $driver->dump($dumpConfig, $dumpFile);

            $this->assertEquals('custom', $dumpResult['format']);
            $this->assertFileExists($dumpFile);
            $this->assertGreaterThan(500, filesize($dumpFile));

            // 4. Verify Archive
            $verifyResult = $driver->verifyArchive($dumpFile);
            $this->assertTrue($verifyResult['verified']);
            $this->assertGreaterThanOrEqual(5, $verifyResult['toc_entries_count']);

            // 5. Restore into target database
            $restoreConfig = array_merge($this->dbConfig, ['database' => $this->targetDb]);
            $restoreResult = $driver->restore($restoreConfig, $dumpFile);
            $this->assertTrue($restoreResult['restored']);

            // 6. Inspect target database and verify all objects and data preserved
            $dstPdo = new PDO(
                "pgsql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->targetDb}",
                $this->dbConfig['username'],
                $this->dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $companies = $dstPdo->query('SELECT * FROM companies ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(1, $companies);
            $this->assertEquals('Acme Global 🚀', $companies[0]['name']);
            $metadata = json_decode((string) $companies[0]['metadata'], true);
            $this->assertEquals('enterprise', $metadata['tier']);

            $projects = $dstPdo->query('SELECT * FROM projects ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(2, $projects);
            $this->assertEquals('Project Apollo', $projects[0]['title']);

            $viewRows = $dstPdo->query('SELECT * FROM v_company_summary')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(1, $viewRows);
            $this->assertEquals(2, (int) $viewRows[0]['project_count']);
            $this->assertEquals(225000.50, (float) $viewRows[0]['total_budget']);
        } finally {
            if (file_exists($dumpFile)) {
                @unlink($dumpFile);
            }
            $this->cleanupDatabases();
        }
    }

    public function test_postgresql_driver_rejects_invalid_credentials(): void
    {
        if (! $this->adminPdo) {
            $this->markTestSkipped('PostgreSQL admin connection not initialized.');
        }

        $driver = new PostgreSqlDriver;
        $failDumpFile = sys_get_temp_dir().'/pg_fail_dump_'.uniqid('', true).'.dump';

        try {
            $invalidConfig = array_merge($this->dbConfig, [
                'database' => $this->sourceDb,
                'password' => 'invalid_unauthorized_pw_'.uniqid('', true),
            ]);

            $this->expectException(BackupProcessException::class);
            $driver->dump($invalidConfig, $failDumpFile);
        } finally {
            if (file_exists($failDumpFile)) {
                @unlink($failDumpFile);
            }
        }
    }
}
