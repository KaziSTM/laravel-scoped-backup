# Testing & Verification Guide

The package includes a comprehensive automated test suite powered by [Orchestra Testbench](https://github.com/orchestral/testbench) and [Pest](https://pestphp.com/).

---

## Test Suite Structure

```text
tests/
├── Pest.php                             # Pest global configuration, test traits, and environment helpers
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
├── Feature/
│   ├── AuditRegressionTest.php          # Regression tests for guarded attributes, WAL isolation, etc.
│   ├── CommandsTest.php                 # Artisan CLI command execution and exit code assertions
│   ├── FullBackupTest.php               # Full backup action, manifest creation, and S3 uploading
│   ├── HealthMonitoringTest.php         # Freshness TTL, size anomaly, and checksum checks
│   ├── MultiDriverFullBackupTest.php    # Real SQLite end-to-end full backup and restore
│   ├── NotificationTest.php             # Discord webhook and email dispatch assertions
│   ├── RemoteStorageSecurityLifecycleTest.php # Remote disk stream operations and permissions
│   ├── ScopedBackupTest.php             # Scoped dataset export, chunking, and JSONL creation
│   ├── ScopedRestorationSafetyTest.php  # Event suppression, ambiguous identity, and transaction rollback
│   └── ScopedRestoreTest.php            # Scoped dataset upsert and dry-run assertions
└── Integration/
    ├── MariaDbRealDriverIntegrationTest.php # Real MariaDB dump, archive verification, and restore
    ├── MySqlRealDriverIntegrationTest.php   # Real MySQL dump, archive verification, and restore
    ├── PostgreSqlRealDriverIntegrationTest.php # Real PostgreSQL custom dump and restore
    ├── RealDatabaseIntegrationTest.php      # Environment binary and version handshake tests
    └── SqliteWalRealDriverIntegrationTest.php  # Real SQLite WAL concurrency and rollback tests
```

---

## Running the Tests

### 1. In Local Development / CI Environment

Ensure development dependencies are installed via Composer:

```bash
composer install
```

Run Pest via Composer script or binary:

```bash
composer test
# or
vendor/bin/pest
```

### 2. Filtering Specific Suites or Tests

```bash
# Run only Unit tests
vendor/bin/pest tests/Unit

# Run only Feature tests
vendor/bin/pest tests/Feature

# Run only Integration tests
vendor/bin/pest tests/Integration

# Run a specific test file
vendor/bin/pest tests/Feature/MultiDriverFullBackupTest.php

# Filter by test name or description
vendor/bin/pest --filter="force fills guarded attributes"
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

1. **Author All Tests in Pest:** Every test must be authored using Pest syntax (`it()`, `expect()`, `beforeEach()`, etc.).
2. **Test Real Behavior Where Practical:** For lightweight engines like SQLite, avoid mocking database connections; test real disk creation, backup, and restore.
3. **Process Argument Verification:** For drivers relying on external utilities (`pg_dump`, `mysqldump`), verify that credentials are passed exclusively via process environment variables and that dangerous shell strings are never evaluated.

---

## Real Database Integration Testing

The package includes real-instance integration tests for PostgreSQL, MySQL, MariaDB, and SQLite (WAL).

### 1. Environment Isolation and Safety
- Integration tests **never** connect to or drop existing developer application databases.
- Each test generates randomized, uniquely prefixed databases (`test_scoped_pkg_*`) using its administrative connection.
- Destructive operations enforce strict prefix assertions (`assertSafeTestDatabaseName`) before any drop action.
- Active connections are forcefully terminated before dropping, and cleanup is executed deterministically via `try/finally` blocks and `afterEach()` hooks.
- Temporary dump files created during tests are unlinked in `finally` blocks, even when tests assert failure exceptions.

### 2. CI vs Local Development Behavior
- **Local Development**: If `DB_*_HOST` is not configured or the target database service is unreachable, tests skip gracefully with an informative message.
- **Continuous Integration (CI)**: When `CI=true` is detected, tests require the configured database services to be healthy and reachable. If a service connection fails, the test fails immediately rather than skipping silently.

### 3. Environment Variables
Configure these variables in your shell or `.env.testing` to enable real database testing locally:

| Variable | Default | Purpose |
| :--- | :--- | :--- |
| `DB_PGSQL_HOST` | `null` (disabled) | PostgreSQL hostname (e.g. `localhost`) |
| `DB_PGSQL_PORT` | `5432` | PostgreSQL port |
| `DB_PGSQL_USERNAME` | `postgres` | Administrative username |
| `DB_PGSQL_PASSWORD` | `""` | Administrative password |
| `DB_PGSQL_DATABASE` | `postgres` | Administrative database for provisioning |
| `DB_MYSQL_HOST` | `null` (disabled) | MySQL hostname (e.g. `localhost`) |
| `DB_MYSQL_PORT` | `3306` | MySQL port |
| `DB_MYSQL_USERNAME` | `root` | Administrative username |
| `DB_MYSQL_PASSWORD` | `""` | Administrative password |
| `DB_MYSQL_DATABASE` | `mysql` | Administrative database for provisioning |
| `DB_MARIADB_HOST` | `null` (disabled) | MariaDB hostname (e.g. `localhost`) |
| `DB_MARIADB_PORT` | `3307` | MariaDB port |
| `DB_MARIADB_USERNAME`| `root` | Administrative username |
| `DB_MARIADB_PASSWORD`| `""` | Administrative password |
| `DB_MARIADB_DATABASE`| `mysql` | Administrative database for provisioning |
