<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration;

use KaziSTM\LaravelScopedBackup\Database\Drivers\PostgreSqlDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use PDO;
use Throwable;

class PostgreSqlRealDriverIntegrationTest extends TestCase
{
    protected ?PDO $adminPdo = null;

    protected string $sourceDb = 'test_scoped_backup_pg_src';

    protected string $targetDb = 'test_scoped_backup_pg_dst';

    protected array $dbConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = env('DB_PGSQL_HOST', 'lerd-postgres');
        $port = (int) env('DB_PGSQL_PORT', 5432);
        $username = env('DB_PGSQL_USERNAME', 'postgres');
        $password = env('DB_PGSQL_PASSWORD', 'lerd');

        try {
            $this->adminPdo = new PDO(
                "pgsql:host={$host};port={$port};dbname=lerd;user={$username};password={$password}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            $this->markTestSkipped("PostgreSQL server unavailable at {$host}:5432 - {$e->getMessage()}");
        }

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
        if ($this->adminPdo) {
            // Terminate open connections before drop
            $this->adminPdo->exec("
                SELECT pg_terminate_backend(pid) 
                FROM pg_stat_activity 
                WHERE datname IN ('{$this->sourceDb}', '{$this->targetDb}') 
                  AND pid <> pg_backend_pid()
            ");

            $this->adminPdo->exec("DROP DATABASE IF EXISTS {$this->sourceDb}");
            $this->adminPdo->exec("DROP DATABASE IF EXISTS {$this->targetDb}");
        }
    }

    public function test_postgresql_driver_full_lifecycle_against_real_instance(): void
    {
        $driver = new PostgreSqlDriver;
        $avail = $driver->checkAvailability();
        $this->assertTrue($avail['available'], 'pg_dump must be available: '.($avail['message'] ?? ''));

        // 1. Create source and target databases
        $this->adminPdo->exec("CREATE DATABASE {$this->sourceDb}");
        $this->adminPdo->exec("CREATE DATABASE {$this->targetDb}");

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
        $dumpFile = sys_get_temp_dir().'/pg_integration_dump_'.uniqid().'.dump';
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
        $metadata = json_decode($companies[0]['metadata'], true);
        $this->assertEquals('enterprise', $metadata['tier']);

        $projects = $dstPdo->query('SELECT * FROM projects ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $projects);
        $this->assertEquals('Project Apollo', $projects[0]['title']);

        $viewRows = $dstPdo->query('SELECT * FROM v_company_summary')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $viewRows);
        $this->assertEquals(2, (int) $viewRows[0]['project_count']);
        $this->assertEquals(225000.50, (float) $viewRows[0]['total_budget']);

        // 7. Verify failure handling
        $invalidConfig = array_merge($this->dbConfig, ['database' => $this->sourceDb, 'password' => 'wrong_pw_xyz']);
        $this->expectException(BackupProcessException::class);
        $driver->dump($invalidConfig, sys_get_temp_dir().'/should_fail.dump');

        if (file_exists($dumpFile)) {
            unlink($dumpFile);
        }
    }
}
