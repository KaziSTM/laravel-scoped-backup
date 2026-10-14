# Laravel Scoped Backup (`kazistm/laravel-scoped-backup`)

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2%20%7C%20%5E8.3%20%7C%20%5E8.4%20%7C%20%5E8.5-blue.svg?style=flat-square)](docs/compatibility.md)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E11.0%20%7C%20%5E12.0%20%7C%20%5E13.0-red.svg?style=flat-square)](docs/compatibility.md)
[![Tests Passing](https://img.shields.io/badge/tests-84%20tests%20(Pest)-success.svg?style=flat-square)](docs/testing.md)

An enterprise-ready Laravel package providing dual backup strategies:
1. **Full Database & Filesystem Backups:** Native, verified database dumps for PostgreSQL, MySQL, MariaDB, and SQLite, optional application filesystem inclusion, atomic ZIP archive packaging with cryptographic JSON manifests, streaming SHA-256 validation, and audit-preserving retention policies.
2. **Deterministic Scoped Dataset Backups:** Chunked JSON Lines (`.jsonl`) streaming of explicitly scoped Eloquent models, related child graphs, and auxiliary entity collections with natural identity key matching, post-restore PostgreSQL sequence synchronization, and configurable transaction isolation.

Includes built-in health monitoring, anomaly detection, distributed concurrency locks, and multi-channel notifications (Email and Webhooks).

---

## The Problem This Package Solves

Standard Laravel backup solutions excel at taking a snapshot of an entire database to remote object storage. However, real-world SaaS, multi-tenant, and regulated applications frequently encounter operational requirements that monolithic full backups cannot address:

- **Selective Tenant or Resource Recovery:** A customer accidentally deletes their project, workspace, or company data. Restoring an entire 500GB production database snapshot to recover one tenant is slow, risky, and disruptive.
- **Data Portability & Migration:** Transferring a specific dataset (with its deep tree of relationships) between environments (e.g. Production to Staging) without exporting sensitive global tables (users, credentials, API keys).
- **Audit-Preserving Retention:** Pruning expired storage files while permanently retaining the audit history of every backup event in your database.
- **Disaster Recovery Preflight:** Verifying archive integrity, table of contents, and cryptographic checksums *before* attempting a destructive restore.

`kazistm/laravel-scoped-backup` solves this by giving you both **native physical database backups** for full disaster recovery and **portable logical scoped backups** for surgical dataset exports and imports.

---

## Architectural Comparison: Full vs. Scoped Backups

| Feature | Full Database Backup | Scoped Dataset Backup |
| :--- | :--- | :--- |
| **Strategy** | Physical / Native utility dump | Logical Eloquent JSONL stream |
| **Supported Engines** | PostgreSQL, MySQL, MariaDB, SQLite | All Eloquent-supported database engines |
| **Scope** | Entire database schema, tables, sequences, routines | Root model, declared relations, auxiliary entities |
| **Format** | Compressed dump (`.dump` / `.sql` / `.sqlite`) inside ZIP | Streaming JSON Lines (`data/*.jsonl`) inside ZIP |
| **Filesystem Inclusion** | Optional (configurable disks and directories) | Optional auxiliary entity arrays |
| **Restore Method** | Native engine restore (`pg_restore`, `mysql`, etc.) | Chunked Eloquent upsert with natural identity matching |
| **Identity Resolution** | Physical primary keys and sequences recreated | Natural keys (`identity`) or primary key mapping |
| **Production Safety** | Requires explicit `--force` confirmation | Non-destructive upsert / safe simulation with `--dry-run` |
| **Primary Use Case** | Disaster recovery, catastrophic failure rollback | Tenant recovery, data portability, staging seeding |

---

## Supported Database Engines

| Database Engine | Native Backup Tool | Native Restore Tool | Supported Objects | Consistency Guarantee |
| :--- | :--- | :--- | :--- | :--- |
| **PostgreSQL** (12+) | `pg_dump` (`-F c` custom binary) | `pg_restore` (TOC verified) | Tables, views, sequences, triggers, functions | Transactional snapshot (`REPEATABLE READ`) |
| **MySQL** (5.7, 8.0, 8.4+) | `mysqldump` (`--single-transaction`) | `mysql` client | Tables, views, triggers, routines, events | Point-in-time InnoDB snapshot |
| **MariaDB** (10.3+) | `mariadb-dump` / `mysqldump` | `mariadb` / `mysql` client | Tables, views, triggers, routines, events | Point-in-time Aria/InnoDB snapshot |
| **SQLite** (3.27+) | `VACUUM INTO` / native backup | Atomic file swap with WAL isolation | Tables, indexes, triggers, views | Full database page copy with WAL frame clearance |

For detailed driver options, binary configuration, and engine caveats, see [Database Drivers Documentation](docs/database-drivers.md).

---

## Requirements

- **PHP:** `^8.2`, `^8.3`, `^8.4`, or `^8.5`
- **Laravel Framework:** `^11.0`, `^12.0`, or `^13.0`
- **Required PHP Extensions:** `ext-zip`, `ext-json`, `ext-pdo`
- **Required External Binaries** (for native full backups):
  - PostgreSQL: `pg_dump`, `pg_restore`
  - MySQL: `mysqldump`, `mysql`
  - MariaDB: `mariadb-dump` (or `mysqldump`), `mariadb` (or `mysql`)
  - SQLite: None (uses native PDO / SQLite engine)

---

## Installation

### 1. Install via Composer

Once the package is available on Packagist, install it via:

```bash
composer require kazistm/laravel-scoped-backup
```

### Local Path Repository (Development)

For local development or testing before public release, configure a Composer path repository in your application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-scoped-backup",
        "options": {
            "symlink": true
        }
    }
],
"require": {
    "kazistm/laravel-scoped-backup": "@dev"
}
```

> [!NOTE]
> Adjust the `url` relative to your application's `composer.json` location, or replace it with an appropriate absolute path.

Link the package and regenerate Composer's autoloader:

```bash
composer update kazistm/laravel-scoped-backup
composer dump-autoload
```

### 2. Publish Configuration & Migration

Publish the configuration file (`config/scoped-backup.php`) and the database tracking migration:

```bash
php artisan vendor:publish --tag=scoped-backup-config
php artisan vendor:publish --tag=scoped-backup-migrations
php artisan migrate
```

For advanced setup details and multi-disk configuration, see [Installation Guide](docs/installation.md) and [Configuration Reference](docs/configuration.md).

---

## Quick Start

### 1. Create a Full Database Backup

Run a native database dump and archive it with a cryptographic manifest:

```bash
# Run backup with default connection, disk, and retention
php artisan scoped-backup:full

# Run backup targeting AWS S3 with filesystem inclusion and 14-day retention
php artisan scoped-backup:full --connection=pgsql --disk=s3 --include-files --retention=14 --trigger=scheduled
```

### 2. Create a Scoped Eloquent Dataset Backup

Snapshot an Eloquent model and its related graph into a portable JSONL archive:

```bash
# Basic scoped backup
php artisan scoped-backup:scoped "App\Models\Company" --name=company-101

# Scoped backup with relations, natural identity key, and custom chunk size
php artisan scoped-backup:scoped "App\Models\Company" \
    --name=company-101 \
    --relations=departments \
    --relations=departments.employees \
    --identity=slug \
    --chunk=250 \
    --disk=s3
```

> [!NOTE]
> In v1.0, scoped dataset backups package root models and serialize declared relations into `records.jsonl`. Scoped restoration (`scoped-backup:restore --type=scoped`) imports and upserts root-model records into the target model table. Child relationships and auxiliary entities are preserved in the archive for auditing or custom extraction, but are not automatically cascade-inserted into child tables.

### 3. Check System Health & Freshness

Diagnose backup staleness, storage size anomalies, record count shifts, and archive corruption:

```bash
php artisan scoped-backup:monitor
```

### 4. Prune Expired Backups

Enforce retention policies while preserving database audit logs:

```bash
# Prune all expired backups according to config defaults
php artisan scoped-backup:prune

# Prune with an explicit retention limit on full backups only
php artisan scoped-backup:prune --retention=7 --type=full
```

### 5. Restore a Backup

Safely restore from full database dumps or scoped datasets:

```bash
# Preview scoped restore without modifying the database (dry-run)
php artisan scoped-backup:restore 42 --type=scoped --dry-run

# Restore scoped dataset archive
php artisan scoped-backup:restore 42 --type=scoped

# Restore full database backup (prompts for interactive safety confirmation in production)
php artisan scoped-backup:restore 42 --type=full --force

# Restore full backup into an isolated staging/recovery connection
php artisan scoped-backup:restore 42 --type=full --connection=pgsql_recovery
```

For complete restoration workflows, see [Restoration Guide](docs/restoration.md).

---

## Programmatic API

The package provides dedicated Action classes for seamless programmatic execution from jobs, controllers, or scheduled commands.

### Defining and Running a Scoped Backup

```php
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;
use App\Models\Company;
use App\Models\AuditLog;

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

echo "Processed {$stats->recordsProcessed} records (Created: {$stats->recordsCreated}, Updated: {$stats->recordsUpdated})";
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

For complete API signatures and data transfer objects, see [Programmatic API](docs/programmatic-api.md).

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

## Testing

The package includes a comprehensive test suite authored in [Pest](https://pestphp.com/) covering unit tests, feature tests, driver mocks, and audit regression tests:

```bash
# Run tests using Pest
composer test
# or
vendor/bin/pest
```

Unit and feature tests run in complete isolation without requiring external database services (using SQLite in-memory fixtures and mocked database drivers). Real database integration tests are isolated and configured via environment variables (see [Testing Guide](docs/testing.md)).

---

## Security

If you discover a security vulnerability within `kazistm/laravel-scoped-backup`, please review our [Security Guide](docs/security.md) or submit a private security advisory on GitHub. Please do not report security vulnerabilities via public GitHub issues.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
