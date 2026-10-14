<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration;

use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use PDO;
use Throwable;

class MySqlRealDriverIntegrationTest extends TestCase
{
    protected ?PDO $adminPdo = null;

    protected string $sourceDb = 'test_scoped_backup_mysql_src';

    protected string $targetDb = 'test_scoped_backup_mysql_dst';

    protected array $dbConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = env('DB_MYSQL_HOST', 'lerd-mysql');
        $port = (int) env('DB_MYSQL_PORT', 3306);
        $username = env('DB_MYSQL_USERNAME', 'root');
        $password = env('DB_MYSQL_PASSWORD', 'lerd');

        try {
            $this->adminPdo = new PDO(
                "mysql:host={$host};port={$port};dbname=lerd",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            $this->markTestSkipped("MySQL server unavailable at {$host}:3306 - {$e->getMessage()}");
        }

        $this->dbConfig = [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
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
        if ($this->adminPdo) {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->sourceDb}`");
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->targetDb}`");
        }
    }

    public function test_mysql_driver_full_lifecycle_against_real_instance(): void
    {
        $driver = new MySqlDriver;
        $avail = $driver->checkAvailability();
        $this->assertTrue($avail['available'], 'mysqldump must be available: '.($avail['message'] ?? ''));

        // 1. Create source and target databases
        $this->adminPdo->exec("CREATE DATABASE `{$this->sourceDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->adminPdo->exec("CREATE DATABASE `{$this->targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // 2. Connect to source database and create schema: tables, views, triggers, stored procedures
        $srcPdo = new PDO(
            "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->sourceDb}",
            $this->dbConfig['username'],
            $this->dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $srcPdo->exec('
            CREATE TABLE departments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(50) UNIQUE NOT NULL,
                settings JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE employees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                department_id INT NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                salary DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                CONSTRAINT fk_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE VIEW v_dept_headcount AS
            SELECT d.id, d.name, COUNT(e.id) AS total_employees
            FROM departments d
            LEFT JOIN employees e ON e.department_id = d.id
            GROUP BY d.id, d.name;

            CREATE TRIGGER trg_dept_uppercase_code
            BEFORE INSERT ON departments
            FOR EACH ROW
            BEGIN
                SET NEW.code = UPPER(NEW.code);
            END;

            CREATE PROCEDURE sp_get_dept_salary(IN p_dept_id INT, OUT p_total DECIMAL(10,2))
            BEGIN
                SELECT COALESCE(SUM(salary), 0.00) INTO p_total FROM employees WHERE department_id = p_dept_id;
            END;
        ');

        // Insert sample records
        $stmt = $srcPdo->prepare('INSERT INTO departments (name, code, settings) VALUES (?, ?, ?)');
        $stmt->execute(['R&D Division 🔬', 'rnd-dept', json_encode(['cluster' => 'alpha', 'nodes' => 5])]);
        $deptId = (int) $srcPdo->lastInsertId();

        $stmtEmp = $srcPdo->prepare('INSERT INTO employees (department_id, full_name, salary) VALUES (?, ?, ?)');
        $stmtEmp->execute([$deptId, 'Alice Engineer', 95000.00]);
        $stmtEmp->execute([$deptId, 'Bob Researcher', 88000.50]);

        // 3. Perform dump
        $dumpFile = sys_get_temp_dir().'/mysql_integration_dump_'.uniqid().'.sql';
        $dumpConfig = array_merge($this->dbConfig, ['database' => $this->sourceDb]);

        $dumpResult = $driver->dump($dumpConfig, $dumpFile);
        $this->assertEquals('sql', $dumpResult['format']);
        $this->assertFileExists($dumpFile);
        $this->assertGreaterThan(500, filesize($dumpFile));

        // 4. Verify Archive
        $verifyResult = $driver->verifyArchive($dumpFile);
        $this->assertTrue($verifyResult['verified']);
        $this->assertEquals('mysql', $verifyResult['driver']);

        // 5. Restore into target database
        $restoreConfig = array_merge($this->dbConfig, ['database' => $this->targetDb]);
        $restoreResult = $driver->restore($restoreConfig, $dumpFile);
        $this->assertTrue($restoreResult['restored']);

        // 6. Inspect target database and verify all objects and data preserved
        $dstPdo = new PDO(
            "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->targetDb}",
            $this->dbConfig['username'],
            $this->dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $depts = $dstPdo->query('SELECT * FROM departments ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $depts);
        $this->assertEquals('R&D Division 🔬', $depts[0]['name']);
        $this->assertEquals('RND-DEPT', $depts[0]['code']); // Trigger worked on insert
        $settings = json_decode($depts[0]['settings'], true);
        $this->assertEquals('alpha', $settings['cluster']);

        $emps = $dstPdo->query('SELECT * FROM employees ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $emps);
        $this->assertEquals('Alice Engineer', $emps[0]['full_name']);

        $viewRows = $dstPdo->query('SELECT * FROM v_dept_headcount')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $viewRows);
        $this->assertEquals(2, (int) $viewRows[0]['total_employees']);

        // Verify stored procedure call
        $dstPdo->exec("CALL sp_get_dept_salary({$depts[0]['id']}, @total_sal)");
        $totalSal = (float) $dstPdo->query('SELECT @total_sal')->fetchColumn();
        $this->assertEquals(183000.50, $totalSal);

        // 7. Test failure handling with bad password
        $invalidConfig = array_merge($this->dbConfig, ['database' => $this->sourceDb, 'password' => 'wrong_mysql_pw']);
        $this->expectException(BackupProcessException::class);
        $driver->dump($invalidConfig, sys_get_temp_dir().'/should_fail_mysql.sql');

        if (file_exists($dumpFile)) {
            unlink($dumpFile);
        }
    }
}
