# Contributing Guide

Contributions are welcome! This document outlines guidelines for contributing to `kazistm/laravel-scoped-backup`.

---

## Code of Conduct & Principles

1. **Strict Types:** Every PHP file must declare strict types (`declare(strict_types=1);`).
2. **Defensive Programming:** Backups are the last line of defense against data loss. Never sacrifice data integrity for speed or syntactic shortcuts.
3. **No Unsanitized Shell Invocations:** Never build shell command strings via string concatenation; always construct explicit command arrays via `Symfony\Component\Process\Process`.
4. **Credential Isolation:** Passwords and secrets must never be exposed via CLI parameters, public exception messages, or log contexts.

---

## Development Workflow

1. Fork the repository and create a feature branch:
   ```bash
   git checkout -b feature/my-new-driver
   ```
2. Install dependencies:
   ```bash
   composer install
   ```
3. Implement your changes following established patterns.
4. Add comprehensive unit and feature tests covering both happy and failure paths.
5. Run the test suite:
   ```bash
   vendor/bin/phpunit
   ```
   *Note*: Unit and Feature tests execute using in-memory SQLite without requiring external databases. To run real database integration tests locally, set the corresponding `DB_*_HOST` variables in your environment (see [Testing Guide](testing.md)).
6. Commit with clear, descriptive commit messages.

---

## How to Add a New Database Driver

To add support for a new database engine (e.g. SQL Server, CockroachDB, ClickHouse):

### 1. Implement `DatabaseBackupDriver`

Create a new driver class in `src/Database/Drivers/` implementing `DatabaseBackupDriver`:

```php
namespace KaziSTM\LaravelScopedBackup\Database\Drivers;

use KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver;

class MyCustomDriver implements DatabaseBackupDriver
{
    public function checkAvailability(): array
    {
        // Check if required CLI tool or PHP extension is available
    }

    public function getCapabilities(): array
    {
        return [
            'name' => 'CustomDriver',
            'driver' => 'custom',
            'archive_format' => 'sql',
            'extension' => 'sql',
            'supports_transactions' => true,
            'requires_external_binaries' => true,
        ];
    }

    public function getArchiveExtension(): string
    {
        return 'sql';
    }

    public function dump(array $config, string $outputPath): array
    {
        // Execute dump into $outputPath
    }

    public function verifyArchive(string $archivePath): array
    {
        // Verify archive integrity
    }

    public function restore(array $config, string $archivePath): array
    {
        // Restore archive into database
    }
}
```

### 2. Register Driver in `DatabaseBackupDriverManager`

Add resolution mapping in `src/Database/DatabaseBackupDriverManager.php`:

```php
return $this->drivers[$normalized] = match ($normalized) {
    'pgsql', 'postgres', 'postgresql' => new PostgreSqlDriver,
    'mysql' => new MySqlDriver,
    'mariadb' => new MariaDbDriver,
    'sqlite' => new SqliteDriver,
    'custom' => new MyCustomDriver,
    default => throw new UnsupportedDatabaseDriverException(...),
};
```

### 3. Add Driver Configuration

Add default driver binary paths and options in `config/scoped-backup.php`.

### 4. Provide Test Coverage

Add unit tests in `tests/Unit/` validating argument construction, error sanitization, and verification checks.
