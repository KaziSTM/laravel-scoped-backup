# Installation

This guide covers installing and bootstrapping `kazistm/laravel-scoped-backup` into your Laravel application.

---

## Requirements

Before installing, ensure your environment satisfies the following minimum requirements:

- **PHP:** `^8.2`, `^8.3`, `^8.4`, or `^8.5`
- **Laravel Framework:** `^11.0`, `^12.0`, or `^13.0`
- **Required PHP Extensions:**
  - `ext-zip`: Required for creating and extracting `.zip` archives.
  - `ext-json`: Required for JSON Lines (JSONL) records and manifests.
  - `ext-pdo`: Required for database interaction.
- **Database Engine Requirements:**
  - **PostgreSQL:** Requires client utilities `pg_dump` and `pg_restore` (PostgreSQL 12+ recommended).
  - **MySQL:** Requires client utilities `mysqldump` and `mysql` (MySQL 5.7, 8.0, 8.4+).
  - **MariaDB:** Requires `mariadb-dump` and `mariadb` (or `mysqldump`/`mysql` fallback; MariaDB 10.3+).
  - **SQLite:** Requires PHP extension `ext-sqlite3` (SQLite 3.27+; no external CLI binary required).

---

## Package Installation

### 1. Via Composer (Standard Packagist Installation)

Once the package is available on Packagist, install it via:

```bash
composer require kazistm/laravel-scoped-backup
```

### 2. Local Path Repository (Monorepo / Local Development)

If you are developing the package locally or integrating it into an application monorepo, add a path repository definition to your root application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "packages/laravel-scoped-backup",
        "options": {
            "symlink": true
        }
    }
],
"require": {
    "kazistm/laravel-scoped-backup": "@dev"
}
```

Then run:

```bash
composer update kazistm/laravel-scoped-backup
```

---

## Service Provider Registration

The package utilizes Laravel's package auto-discovery mechanism. The service provider `KaziSTM\LaravelScopedBackup\ScopedBackupServiceProvider` will be automatically registered when Composer installs the package.

If your application has disabled auto-discovery in `composer.json` (`"dont-discover": ["*"]`), manually register the provider in `bootstrap/providers.php` (Laravel 11+) or `config/app.php`:

```php
// bootstrap/providers.php (Laravel 11+)
return [
    App\Providers\AppServiceProvider::class,
    KaziSTM\LaravelScopedBackup\ScopedBackupServiceProvider::class,
];
```

---

## Publishing Assets

Publish the package configuration file and database migrations using Artisan:

### 1. Publish Configuration

```bash
php artisan vendor:publish --tag=scoped-backup-config
```

This publishes the configuration file to `config/scoped-backup.php`.

### 2. Publish Database Migrations

```bash
php artisan vendor:publish --tag=scoped-backup-migrations
```

This creates a timestamped migration in `database/migrations/*_create_backup_records_table.php` targeting the tracking table (default: `database_backups`).

---

## Database Migration

Run your application migrations to create the backup tracking table:

```bash
php artisan migrate
```

The migration creates the tracking table with appropriate status, driver, trigger, duration, checksum, and pruning indexes.

---

## Storage Preparation

The package creates and verifies backup archives on any configured Laravel Flysystem disk (e.g. `local`, `public`, `s3`).

1. Ensure the target storage disk is configured in `config/filesystems.php`.
2. By default, backups use the `local` disk and are stored in the `backups` directory (`storage/app/backups`).
3. Ensure the web server or CLI process user has write permissions to:
   - Your target storage disk path.
   - The temporary staging directory: `storage/framework/cache/backups`.

---

## Verifying the Installation

To verify that the package is correctly installed, registered, and configured, execute the monitor command:

```bash
php artisan scoped-backup:monitor
```

If no backups exist yet, you will see a fresh health status report indicating that the monitor is operational.

---

## Next Steps

- Consult the [Configuration Reference](configuration.md) to customize database connections, timeout settings, and retention rules.
- Read [Getting Started](getting-started.md) for a hands-on walkthrough of creating your first full and scoped backups.
- Review [Database Drivers](database-drivers.md) for database-specific requirements and verification behaviors.
