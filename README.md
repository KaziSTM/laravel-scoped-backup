# Laravel Scoped Backup (`kazistm/laravel-scoped-backup`)

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![PHP Compatibility](https://badge.laravel.cloud/php-badge/KaziSTM/laravel-scoped-backup)](https://packagist.org/packages/KaziSTM/laravel-scoped-backup)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/kazistm/laravel-scoped-backup?style=flat)](https://packagist.org/packages/kazistm/laravel-scoped-backup)
[![Tests Passing](https://img.shields.io/badge/tests-75%20passed%20(Pest)-success.svg?style=flat-square)](docs/testing.md)

Laravel backup and recovery package providing dual backup strategies: **native full-database disaster recovery dumps** (PostgreSQL, MySQL, MariaDB, SQLite) and **surgical scoped Eloquent dataset snapshots** (portable JSON Lines streams with natural-key upsert restoration), accompanied by streaming cryptographic SHA-256 integrity verification, safe dry-run simulation, audit-preserving retention policies, and automated health monitoring.

---

## Key Capabilities & Differentiators

Standard Laravel backup solutions snapshot an entire database to remote object storage. While effective for monolithic disaster recovery, production SaaS, multi-tenant, and regulated systems routinely face operational needs that full-database snapshots cannot solve alone:

- **Surgical Tenant & Resource Recovery:** Recover a single tenant, workspace, or company dataset without restoring a 500GB production database snapshot or impacting other customers.
- **Environment Data Portability:** Export specific domain models and relation graphs (e.g. from Production to Staging or local dev) without exposing global credentials, sensitive user passwords, or API keys.
- **Cryptographic Archive Integrity:** Compute streaming SHA-256 checksums stored in machine-readable `manifest.json` files and verify archives before attempting destructive restores.
- **Simulated Dry-Run Restorations:** Validate scoped dataset formatting, model resolution, and natural-key row matching without writing to the database.
- **Audit-Preserving Retention:** Automatically prune expired physical backup archives from storage while permanently preserving tracking records, checksums, and execution metrics in the database.
- **Comprehensive Health Monitoring:** Diagnose backup staleness (TTL), archive corruption, and storage size drop anomalies through an Artisan health check designed for uptime monitors and alerting systems.

---

## Requirements & Compatibility

- **PHP:** `^8.2`, `^8.3`, `^8.4`, or `^8.5`
- **Laravel Framework:** `^11.0`, `^12.0`, or `^13.0`
- **Required PHP Extensions:** `ext-zip`, `ext-json`, `ext-pdo`
- **Supported Database Engines:**
  - **PostgreSQL** (12+): Native `pg_dump` (`-F c` custom compressed binary) and `pg_restore` (TOC verified). Requires `pg_dump` and `pg_restore` client binaries.
  - **MySQL** (5.7, 8.0, 8.4+): Native `mysqldump` (`--single-transaction`, `--quick`) and `mysql` client.
  - **MariaDB** (10.3+): Native `mariadb-dump` / `mariadb` with automatic fallback to `mysqldump` and dynamic MariaDB detection.
  - **SQLite** (3.27+): Native `SQLite3::backup()` online backup API with WAL frame clearance and atomic staging rollback. No external CLI binaries required (uses PHP `ext-sqlite3`).

For full engine parameters, privilege requirements, and binary settings, see [Database Drivers Documentation](docs/database-drivers.md).

---

## Installation

### 1. Install via Composer

Install the package into your application via Composer:

```bash
composer require kazistm/laravel-scoped-backup
```

### 2. Publish Configuration & Migration

Publish the configuration file (`config/scoped-backup.php`) and the database tracking migration:

```bash
php artisan vendor:publish --tag=scoped-backup-config
php artisan vendor:publish --tag=scoped-backup-migrations
php artisan migrate
```

For advanced multi-disk configuration, see the [Installation Guide](docs/installation.md) and [Configuration Reference](docs/configuration.md).

---

## Quick Start

### 1. Create a Full Database Backup

Run an engine-native database dump packaged into an atomic ZIP archive with manifest:

```bash
# Backup default connection to configured storage disk
php artisan scoped-backup:full

# Backup targeting S3 with filesystem inclusion, 14-day retention, and custom connection
php artisan scoped-backup:full --connection=pgsql --disk=s3 --include-files --retention=14 --trigger=scheduled
```

### 2. Create a Scoped Eloquent Dataset Backup

Snapshot an Eloquent model, declared relationships, and auxiliary collections into a portable JSONL archive:

```bash
# Basic scoped model export
php artisan scoped-backup:scoped "App\Models\Company" --name=company-101

# Scoped export with relationships, natural identity key, and custom chunk size
php artisan scoped-backup:scoped "App\Models\Company" \
    --name=company-101 \
    --relations=departments \
    --relations=departments.employees \
    --identity=slug \
    --chunk=250 \
    --disk=s3
```

### 3. Check System Health & Freshness

Evaluate backup freshness, storage size drop anomalies, and archive SHA-256 integrity:

```bash
php artisan scoped-backup:monitor
```

### 4. Prune Expired Backups

Enforce retention limits while preserving audit rows in your database:

```bash
# Prune using configured retention defaults
php artisan scoped-backup:prune

# Override retention count on full backups only
php artisan scoped-backup:prune --retention=7 --type=full
```

### 5. Restore a Backup

Restore from full database dumps or scoped datasets by Backup ID or file path:

```bash
# Preview scoped restore without modifying the database (dry-run simulation)
php artisan scoped-backup:restore 42 --type=scoped --dry-run

# Live restore of a scoped dataset archive
php artisan scoped-backup:restore 42 --type=scoped

# Pre-flight verify a full backup without restoring
php artisan scoped-backup:restore 42 --type=full --verify-only

# Restore full database backup (prompts for confirmation in production, or pass --force)
php artisan scoped-backup:restore 42 --type=full --force

# Restore full backup into an isolated staging/recovery connection
php artisan scoped-backup:restore 42 --type=full --connection=pgsql_recovery
```

---

## Architectural Comparison: Full vs. Scoped Backups

| Feature | Full Database Backup | Scoped Dataset Backup |
| :--- | :--- | :--- |
| **Strategy** | Native engine dump (CLI utility / online backup API) | Logical Eloquent JSONL stream |
| **Supported Engines** | PostgreSQL, MySQL, MariaDB, SQLite | All Eloquent-supported database engines |
| **Scope** | Entire database schema, tables, routines, sequences | Root model, declared relations, auxiliary entities |
| **Archive Format** | Dump artifact (`.dump` / `.sql` / `.sqlite`) inside ZIP | Streaming JSON Lines (`data/*.jsonl`) inside ZIP |
| **Filesystem Inclusion** | Optional (configurable disks and directories) | Optional auxiliary entity JSON collections |
| **Restore Method** | Native engine restore (`pg_restore`, `mysql`, etc.) | Chunked Eloquent upsert with natural identity matching |
| **Identity Resolution** | Physical primary keys and sequences recreated | Natural keys (`identity`) or primary key mapping |
| **Production Safety** | Requires explicit `--force` confirmation | Non-destructive upsert / safe simulation with `--dry-run` |
| **Primary Use Case** | Disaster recovery, catastrophic failure rollback | Tenant recovery, data portability, staging seeding |

---

## Important Restoration Limitations & Safety Considerations

- **Root-Model Scope during Scoped Restore:**
  `ScopedExporter` packages root model records and serializes declared relations into `records.jsonl`, plus supplementary entities into `entities/`. During restoration, `ScopedImporter` imports and upserts root model records into the target model table. Eager-loaded child relationships and auxiliary entity arrays are preserved in the archive for auditing, inspection, or custom extraction, but are **not automatically cascade-inserted** into child tables by `ScopedImporter`.
- **Target Schema Prerequisite:**
  Scoped backups export data records, not table DDL, schema definitions, or database views. Target database tables must already exist before performing a scoped restore.
- **Simulated vs. Live Restore:**
  `--dry-run` mode parses JSON Lines records, resolves target models, and computes match metrics against current database rows without writing to disk. A successful dry run verifies archive format and model resolution, but **does not guarantee** that a live restore will succeed under concurrent writes or unmodeled database-level foreign key constraints.
- **Production Restore Guard:**
  Destructive full database restorations are blocked in production environments (`APP_ENV=production`) unless `--force` is explicitly passed or confirmed interactively. Always prefer testing restores into an isolated recovery connection (`--connection=pgsql_recovery`).

For full restoration mechanics, see the [Restoration Guide](docs/restoration.md).

---

## Programmatic API

The package provides dedicated single-responsibility Action classes designed for dependency injection:

### Defining and Running a Scoped Backup

```php
use App\Models\AuditLog;
use App\Models\Company;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;

$definition = (new ScopedBackupDefinition('company-acme'))
    ->model(Company::class, fn ($query) => $query->where('slug', 'acme-corp'))
    ->include([
        'users.roles',
        'projects.tasks',
        'documents.metadata',
    ])
    ->identity(['slug', 'company_code'])
    ->chunkSize(200)
    ->withTransaction(true, 'REPEATABLE READ')
    ->addEntity('compliance_logs', function () {
        return AuditLog::where('company_id', 1)->get()->toArray();
    });

/** @var CreateScopedBackupAction $action */
$action = app(CreateScopedBackupAction::class);
$result = $action->execute($definition);

// Access tracking record and runtime statistics
$record = $result['record']; // KaziSTM\LaravelScopedBackup\Models\BackupRecord
$stats  = $result['stats'];  // KaziSTM\LaravelScopedBackup\DTO\BackupStats
```

### Executing Full Backups Programmatically

```php
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;

$action = app(CreateFullBackupAction::class);

$backupRecord = $action->execute(
    trigger: BackupTrigger::SCHEDULED,
    connection: 'pgsql',
    disk: 's3',
    path: 'daily-backups',
    retentionLimit: 30,
    includeFiles: true,
);
```

### Programmatic Scoped Restoration

```php
use KaziSTM\LaravelScopedBackup\Actions\RestoreScopedBackupAction;

$action = app(RestoreScopedBackupAction::class);

$stats = $action->execute(
    archivePath: storage_path('app/backups/company-acme-2026-10-14.zip'),
    options: [
        'dry_run' => false,
        'without_events' => true,
    ]
);

echo "Processed {$stats->totalProcessed()} records (Created: {$stats->recordsCreated}, Updated: {$stats->recordsUpdated})";
```

### Programmatic Health Diagnostics

```php
use KaziSTM\LaravelScopedBackup\Actions\VerifyBackupHealthAction;

$verifier = app(VerifyBackupHealthAction::class);
$result = $verifier->check();

if (! $result->isHealthy) {
    foreach ($result->failures() as $failure) {
        logger()->error("Backup Health Failure [{$failure['check']}]: {$failure['message']}");
    }
}
```

For complete method signatures, DTO properties, and event payloads, see the [Programmatic API Reference](docs/programmatic-api.md).

---

## Testing

The package includes an extensive test suite authored in [Pest](https://pestphp.com/) covering unit tests, feature workflows, mock driver validations, and audit regression tests:

```bash
# Run tests using Pest
composer test
# or
vendor/bin/pest
```

Unit and feature tests execute in complete isolation without requiring external database services (using SQLite in-memory fixtures and mocked database drivers). Dedicated integration tests for PostgreSQL, MySQL, MariaDB, and SQLite WAL are configurable via environment variables (see [Testing Guide](docs/testing.md)).

---

## Documentation Navigation

Comprehensive guides and architectural specifications are available in the [`docs/`](docs/) directory:

| Document | Description |
| :--- | :--- |
| [**Installation**](docs/installation.md) | Composer setup, service provider registration, migrations, and requirements. |
| [**Configuration**](docs/configuration.md) | Complete reference for all `config/scoped-backup.php` options and env variables. |
| [**Getting Started**](docs/getting-started.md) | Step-by-step onboarding walkthrough for beginners. |
| [**Full Backups**](docs/full-backups.md) | Native dumps, filesystem bundling, ZIP manifests, and checksum validation. |
| [**Scoped Backups**](docs/scoped-backups.md) | Defining scoped Eloquent datasets, relation trees, JSONL streaming, and identity resolution. |
| [**Restoration**](docs/restoration.md) | Full disaster recovery, dry-run simulation, scoped upserts, and sequence sync. |
| [**Retention**](docs/retention.md) | Automated pruning policies, audit-record preservation, and safety guards. |
| [**Health Monitoring**](docs/health-monitoring.md) | Staleness detection, size anomaly thresholds, and deep archive integrity checks. |
| [**Notifications**](docs/notifications.md) | Configuring Email alerts and Slack/Discord webhook notifications. |
| [**Storage & Disks**](docs/storage.md) | Staging directories, local storage, AWS S3, Cloudflare R2, and remote streaming. |
| [**Database Drivers**](docs/database-drivers.md) | Deep technical guide for PostgreSQL, MySQL, MariaDB, and SQLite drivers. |
| [**Artisan Commands**](docs/artisan-commands.md) | Complete CLI reference with exact arguments, options, and exit codes. |
| [**Programmatic API**](docs/programmatic-api.md) | Action classes, DTOs, contracts, and custom integration patterns. |
| [**Scheduling**](docs/scheduling.md) | Laravel task scheduler recipes, cron configurations, and backup intervals. |
| [**Security**](docs/security.md) | Credential masking, Zip Slip mitigation, permissions, and production safeguards. |
| [**Troubleshooting**](docs/troubleshooting.md) | Diagnosis and remediation for common driver, storage, and timeout issues. |
| [**Testing**](docs/testing.md) | Running unit, feature, and driver integration tests with Orchestra Testbench. |
| [**Contributing**](docs/contributing.md) | Coding standards, pull request workflow, and adding database drivers. |
| [**Architecture**](docs/architecture.md) | High-level system design, pipeline flowcharts, and sequence diagrams. |
| [**Compatibility**](docs/compatibility.md) | Matrix of supported PHP, Laravel, and database engine versions. |
| [**Changelog**](docs/changelog.md) | Detailed release notes and historical change log. |

---

## Security

If you discover a security vulnerability within `kazistm/laravel-scoped-backup`, please review our [Security Guide](docs/security.md) or submit a private security advisory on GitHub. Please do not report security vulnerabilities via public GitHub issues.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
