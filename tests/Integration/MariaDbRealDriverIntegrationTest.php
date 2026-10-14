<?php

declare(strict_types=1);

use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;

beforeEach(function (): void {
    $this->host = env('DB_MARIADB_HOST');
    $this->port = (int) (env('DB_MARIADB_PORT') ?: 3307);
    $this->username = (string) (env('DB_MARIADB_USERNAME') ?: 'root');
    $this->password = env('DB_MARIADB_PASSWORD') !== null ? (string) env('DB_MARIADB_PASSWORD') : '';
    $this->adminDb = (string) (env('DB_MARIADB_DATABASE') ?: 'mysql');

    if (empty($this->host)) {
        handleServiceUnavailable('mariadb', null, $this->port);
        return;
    }

    try {
        $this->adminPdo = new PDO(
            "mysql:host={$this->host};port={$this->port};dbname={$this->adminDb}",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );

        $version = (string) $this->adminPdo->query('SELECT version()')->fetchColumn();
        if (stripos($version, 'mariadb') === false) {
            handleServiceUnavailable('mariadb', $this->host, $this->port, new RuntimeException("Server at {$this->host}:{$this->port} reported {$version}, expected MariaDB."));
            return;
        }
    } catch (Throwable $e) {
        handleServiceUnavailable('mariadb', $this->host, $this->port, $e);
        return;
    }

    $uniqueSuffix = bin2hex(random_bytes(4));
    $this->sourceDb = "test_scoped_pkg_mariadb_src_{$uniqueSuffix}";
    $this->targetDb = "test_scoped_pkg_mariadb_dst_{$uniqueSuffix}";

    $this->dbConfig = [
        'driver' => 'mariadb',
        'host' => $this->host,
        'port' => (string) $this->port,
        'username' => $this->username,
        'password' => $this->password,
        'charset' => 'utf8mb4',
    ];

    $this->cleanupDatabases = function (): void {
        if (isset($this->adminPdo) && ! empty($this->sourceDb) && ! empty($this->targetDb)) {
            try {
                assertSafeTestDatabaseName($this->sourceDb);
                assertSafeTestDatabaseName($this->targetDb);
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->sourceDb}`");
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->targetDb}`");
            } catch (Throwable) {
                // Ignore cleanup errors on tearDown
            }
        }
    };

    ($this->cleanupDatabases)();
});

afterEach(function (): void {
    if (isset($this->cleanupDatabases)) {
        ($this->cleanupDatabases)();
    }
});

it('reports MariaDB driver utilities and availability', function (): void {
    $driver = new MariaDbDriver;
    $avail = $driver->checkAvailability();

    $caps = $driver->getCapabilities();
    expect($caps['name'])->toBe('MariaDB')
        ->and($caps['driver'])->toBe('mariadb')
        ->and($driver->getArchiveExtension())->toBe('sql');

    if ($avail['available']) {
        expect($avail['binary'])->not->toBeEmpty()
            ->and($avail['version'])->not->toBeEmpty();
    } else {
        expect($avail['message'])->toContain('not found');
    }
});

it('executes MariaDB full lifecycle when server is available', function (): void {
    if (! isset($this->adminPdo) || ! $this->adminPdo) {
        test()->markTestSkipped('MariaDB admin connection not initialized.');
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

        expect($dumpResult['format'])->toBe('sql')
            ->and(file_exists($dumpFile))->toBeTrue();

        // 4. Verify Archive
        $verifyResult = $driver->verifyArchive($dumpFile);
        expect($verifyResult['verified'])->toBeTrue();

        // 5. Restore into target database
        $restoreConfig = array_merge($this->dbConfig, ['database' => $this->targetDb]);
        $restoreResult = $driver->restore($restoreConfig, $dumpFile);
        expect($restoreResult['restored'])->toBeTrue();

        // 6. Inspect target database and verify all data & triggers
        $dstPdo = new PDO(
            "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->targetDb}",
            $this->dbConfig['username'],
            $this->dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $items = $dstPdo->query('SELECT * FROM inventory ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        expect($items)->toHaveCount(2)
            ->and($items[0]['sku'])->toBe('PROD-001-🇲🇦');

        $auditRows = $dstPdo->query('SELECT * FROM audit_trail ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        expect($auditRows)->toHaveCount(2);

        $dstPdo->exec('CALL sp_get_total_stock(@total_stock)');
        $totalStock = (int) $dstPdo->query('SELECT @total_stock')->fetchColumn();
        expect($totalStock)->toBe(450);
    } finally {
        if (file_exists($dumpFile)) {
            @unlink($dumpFile);
        }
        ($this->cleanupDatabases)();
    }
});

it('rejects invalid credentials on MariaDB dump', function (): void {
    if (! isset($this->adminPdo) || ! $this->adminPdo) {
        test()->markTestSkipped('MariaDB admin connection not initialized.');
    }

    $driver = new MariaDbDriver;
    $failDumpFile = sys_get_temp_dir().'/mariadb_fail_dump_'.uniqid('', true).'.sql';

    try {
        $invalidConfig = array_merge($this->dbConfig, [
            'database' => $this->sourceDb,
            'password' => 'invalid_unauthorized_pw_'.uniqid('', true),
        ]);

        expect(fn () => $driver->dump($invalidConfig, $failDumpFile))
            ->toThrow(BackupProcessException::class);
    } finally {
        if (file_exists($failDumpFile)) {
            @unlink($failDumpFile);
        }
    }
});
