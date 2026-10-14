# Testing & Verification Guide

The package includes a comprehensive automated test suite powered by [Orchestra Testbench](https://github.com/orchestral/testbench) and [PHPUnit](https://phpunit.de/).

---

## Test Suite Structure

```text
tests/
├── TestCase.php                         # Base Testbench TestCase with migrations & sqlite memory setup
├── Fixtures/
│   └── User.php                         # Model fixture for standalone scoped tests
├── Unit/
│   ├── ArchiveManagerTest.php           # ZIP creation, extraction, Zip Slip, and Zip Bomb limits
│   ├── DatabaseBackupDriverManagerTest.php # Driver resolution and extension tests
│   ├── MariaDbDriverTest.php            # MariaDB binary discovery and fallback logic
│   ├── MySqlDriverTest.php              # MySQL process argument construction and verification
│   ├── PostgresDumperTest.php           # PostgreSQL dump argument and TOC validation
│   └── SqliteDriverTest.php             # Native SQLite3 online backup API and integrity checks
└── Feature/
    ├── AuditRegressionTest.php          # Regression tests for guarded attributes, WAL isolation, etc.
    ├── CommandsTest.php                 # Artisan CLI command execution and exit code assertions
    ├── FullBackupTest.php               # Full backup action, manifest creation, and S3 uploading
    ├── HealthMonitoringTest.php         # Freshness TTL, size anomaly, and checksum checks
    ├── MultiDriverFullBackupTest.php    # Real SQLite end-to-end full backup and restore
    ├── NotificationTest.php             # Discord webhook and email dispatch assertions
    ├── ScopedBackupTest.php             # Scoped dataset export, chunking, and JSONL creation
    └── ScopedRestoreTest.php            # Scoped dataset upsert and dry-run assertions
```

---

## Running the Tests

### 1. In Local Development / CI Environment

Ensure development dependencies are installed via Composer:

```bash
composer install
```

Run PHPUnit:

```bash
vendor/bin/phpunit
```

### 2. Filtering Specific Suites or Tests

```bash
# Run only Unit tests
vendor/bin/phpunit --testsuite=Unit

# Run only Feature tests
vendor/bin/phpunit --testsuite=Feature

# Run a specific test class
vendor/bin/phpunit tests/Feature/MultiDriverFullBackupTest.php

# Filter by test name
vendor/bin/phpunit --filter=test_scoped_importer_force_fills_guarded_attributes
```

---

## Test Environment Mechanics

Tests extend `KaziSTM\LaravelScopedBackup\Tests\TestCase`, which:

1. Loads `ScopedBackupServiceProvider` into the Orchestra Testbench application sandbox.
2. Configures in-memory SQLite (`:memory:`) as the default database connection.
3. Automatically runs package migration stubs (`create_backup_records_table.php.stub`).
4. Prepares standard model fixtures (`User`, `GuardedModel`) for scoped testing.
5. Employs `Storage::fake('local')` and `Storage::fake('s3')` to prevent disk contamination.

---

## Writing Tests for New Drivers or Features

When contributing a new database driver or feature:

1. **Test Real Behavior Where Practical:** For lightweight engines like SQLite, avoid mocking database connections; test real disk creation, backup, and restore.
2. **Process Argument Verification:** For drivers relying on external utilities (`pg_dump`, `mysqldump`), verify that credentials are passed exclusively via process environment variables and that dangerous shell strings are never evaluated.
3. **Failure Paths:** Always write tests asserting that missing files, non-zero process exit codes, and corrupted archives throw the expected typed exceptions (`BackupProcessException`, `BackupVerificationException`, `RestoreException`).
