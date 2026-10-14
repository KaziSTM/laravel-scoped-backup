<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration;

use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Tests\Integration\Concerns\InteractsWithRealDatabases;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use PDO;
use Throwable;

class MariaDbRealDriverIntegrationTest extends TestCase
{
    use InteractsWithRealDatabases;

    protected ?PDO $adminPdo = null;

    protected string $sourceDb = '';

    protected string $targetDb = '';

    protected array $dbConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = env('DB_MARIADB_HOST');
        $port = (int) (env('DB_MARIADB_PORT') ?: 3307);
        $username = (string) (env('DB_MARIADB_USERNAME') ?: 'root');
        $password = env('DB_MARIADB_PASSWORD') !== null ? (string) env('DB_MARIADB_PASSWORD') : '';
        $adminDb = (string) (env('DB_MARIADB_DATABASE') ?: 'mysql');

        if (empty($host)) {
            $this->handleServiceUnavailable('mariadb', null, $port);
            return;
        }

        try {
            $this->adminPdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$adminDb}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );

            $version = (string) $this->adminPdo->query('SELECT version()')->fetchColumn();
            if (stripos($version, 'mariadb') === false) {
                // If it connected to a non-MariaDB instance
                $this->handleServiceUnavailable('mariadb', $host, $port, new \RuntimeException("Server at {$host}:{$port} reported {$version}, expected MariaDB."));
                return;
            }
        } catch (Throwable $e) {
            $this->handleServiceUnavailable('mariadb', $host, $port, $e);
            return;
        }

        $uniqueSuffix = bin2hex(random_bytes(4));
        $this->sourceDb = "test_scoped_pkg_mariadb_src_{$uniqueSuffix}";
        $this->targetDb = "test_scoped_pkg_mariadb_dst_{$uniqueSuffix}";

        $this->dbConfig = [
            'driver' => 'mariadb',
            'host' => $host,
            'port' => (string) $port,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
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
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->sourceDb}`");
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->targetDb}`");
            } catch (Throwable) {
                // Ignore cleanup errors on tearDown
            }
        }
    }

    public function test_mariadb_driver_utilities_and_availability(): void
    {
        $driver = new MariaDbDriver;
        $avail = $driver->checkAvailability();

        $caps = $driver->getCapabilities();
        $this->assertEquals('MariaDB', $caps['name']);
        $this->assertEquals('mariadb', $caps['driver']);
        $this->assertEquals('sql', $driver->getArchiveExtension());

        if ($avail['available']) {
            $this->assertNotEmpty($avail['binary']);
            $this->assertNotEmpty($avail['version']);
        } else {
            $this->assertStringContainsString('not found', $avail['message']);
        }
    }

    public function test_mariadb_full_lifecycle_when_server_available(): void
    {
        if (! $this->adminPdo) {
            $this->markTestSkipped('MariaDB admin connection not initialized.');
        }

        $driver = new MariaDbDriver;
        $dumpFile = sys_get_temp_dir().'/mariadb_integration_dump_'.uniqid('', true).'.sql';

        try {
            // 1. Create dedicated source and target databases
            $this->adminPdo->exec("CREATE DATABASE `{$this->sourceDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->adminPdo->exec("CREATE DATABASE `{$this->targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $srcPdo = new PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->sourceDb}",
                $this->dbConfig['username'],
                $this->dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // 2. Build MariaDB schema (InnoDB & triggers & procedures)
            $srcPdo->exec("
                CREATE TABLE inventory (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sku VARCHAR(100) UNIQUE NOT NULL,
                    stock INT NOT NULL DEFAULT 0,
                    details JSON
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE audit_trail (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sku VARCHAR(100) NOT NULL,
                    change_type VARCHAR(50) NOT NULL,
                    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TRIGGER trg_inventory_audit
                AFTER INSERT ON inventory
                FOR EACH ROW
                BEGIN
                    INSERT INTO audit_trail (sku, change_type) VALUES (NEW.sku, 'INSERT');
                END;

                CREATE PROCEDURE sp_get_total_stock(OUT p_total INT)
                BEGIN
                    SELECT COALESCE(SUM(stock), 0) INTO p_total FROM inventory;
                END;
            ");

            $stmt = $srcPdo->prepare('INSERT INTO inventory (sku, stock, details) VALUES (?, ?, ?)');
            $stmt->execute(['PROD-001-🇲🇦', 150, json_encode(['warehouse' => 'Tangier', 'aisle' => 4])]);
            $stmt->execute(['PROD-002-🇩🇿', 300, json_encode(['warehouse' => 'Algiers', 'aisle' => 8])]);

            // 3. Perform dump
            $dumpConfig = array_merge($this->dbConfig, ['database' => $this->sourceDb]);
            $dumpResult = $driver->dump($dumpConfig, $dumpFile);

            $this->assertEquals('sql', $dumpResult['format']);
            $this->assertFileExists($dumpFile);

            // 4. Verify Archive
            $verifyResult = $driver->verifyArchive($dumpFile);
            $this->assertTrue($verifyResult['verified']);

            // 5. Restore into target database
            $restoreConfig = array_merge($this->dbConfig, ['database' => $this->targetDb]);
            $restoreResult = $driver->restore($restoreConfig, $dumpFile);
            $this->assertTrue($restoreResult['restored']);

            // 6. Inspect target database and verify all data & triggers
            $dstPdo = new PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->targetDb}",
                $this->dbConfig['username'],
                $this->dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $items = $dstPdo->query('SELECT * FROM inventory ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(2, $items);
            $this->assertEquals('PROD-001-🇲🇦', $items[0]['sku']);

            $auditRows = $dstPdo->query('SELECT * FROM audit_trail ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(2, $auditRows);

            $dstPdo->exec('CALL sp_get_total_stock(@total_stock)');
            $totalStock = (int) $dstPdo->query('SELECT @total_stock')->fetchColumn();
            $this->assertEquals(450, $totalStock);
        } finally {
            if (file_exists($dumpFile)) {
                @unlink($dumpFile);
            }
            $this->cleanupDatabases();
        }
    }

    public function test_mariadb_driver_rejects_invalid_credentials(): void
    {
        if (! $this->adminPdo) {
            $this->markTestSkipped('MariaDB admin connection not initialized.');
        }

        $driver = new MariaDbDriver;
        $failDumpFile = sys_get_temp_dir().'/mariadb_fail_dump_'.uniqid('', true).'.sql';

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
