# Getting Started

This guide provides a practical walkthrough of using `kazistm/laravel-scoped-backup` to create, monitor, and restore backups.

---

## Core Concepts: Two Backup Paradigms

1. **Full Database & Filesystem Backups (`scoped-backup:full`):**
   - Creates native database dumps using engine-specific utilities (`pg_dump -F c`, `mysqldump`, `SQLite3::backup()`).
   - Packages the dump, optional application files, and a verified `manifest.json` into a single timestamped `.zip` archive.
   - Ideal for disaster recovery, staging replication, and cold storage.

2. **Scoped Model & Dataset Snapshots (`scoped-backup:scoped`):**
   - Exports a precisely defined subset of Eloquent models, relationships, and supplementary entities as streaming JSON Lines (`records.jsonl`).
   - Includes natural identity keys for safe, idempotent upsert restoration.
   - Ideal for tenant backups, audit snapshots, provider exports, and test fixtures.

---

## 1. Creating Your First Full Backup

To create a full database backup using your default connection and storage disk:

```bash
php artisan scoped-backup:full
```

### Output

The command will display an overview table of the generated backup:

```text
Initiating manual full backup...
+-----------------+-----------------------------------------------------------+
| Attribute       | Value                                                     |
+-----------------+-----------------------------------------------------------+
| Backup ID       | 1                                                         |
| Status          | succeeded                                                 |
| Trigger         | manual                                                    |
| Database        | app_production                                            |
| Driver          | pgsql                                                     |
| Host            | 127.0.0.1                                                 |
| Disk            | local                                                     |
| File Path       | backups/app_production_2026-10-14_140000_a1b2c3d4.zip     |
| Size            | 4,219,850 bytes                                           |
| SHA-256         | e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991... |
| Duration        | 2.145s                                                    |
| Completed At    | 2026-10-14T14:00:02+01:00                                 |
+-----------------+-----------------------------------------------------------+
Full backup completed and verified successfully.
```

### Full Backup Options

```bash
# Backup a specific connection to remote storage (e.g. S3) and include files
php artisan scoped-backup:full --connection=mysql --disk=s3 --include-files --retention=14
```

---

## 2. Creating a Scoped Dataset Snapshot

To snapshot an Eloquent model and its relationships:

```bash
php artisan scoped-backup:scoped "App\Models\User" --name=active_users --identity=email --chunk=50
```

### Programmatic Scoped Backup Example

For complex scopes, queries, and multi-relation exports, use the fluent definition builder:

```php
use App\Models\Organization;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;

$definition = ScopedBackupDefinition::make('active_organizations')
    ->model(Organization::class, fn ($query) => $query->where('is_active', true))
    ->include(['members', 'settings', 'subscriptions'])
    ->identity('slug')
    ->chunkSize(100)
    ->withTransaction(true, 'REPEATABLE READ')
    ->addEntity('plans_reference', function () {
        return \App\Models\Plan::all()->toArray();
    });

$result = app(CreateScopedBackupAction::class)->execute($definition);

$record = $result['record']; // BackupRecord instance
$stats = $result['stats'];   // BackupStats ($stats->recordsExported, etc.)
```

---

## 3. Checking Backup Health

To monitor the operational health of your backup system:

```bash
php artisan scoped-backup:monitor
```

### Example Health Output

```text
Evaluating backup system health...
+--------------------+---------+-----------------------------------------------------------+
| Check Name         | Status  | Details                                                   |
+--------------------+---------+-----------------------------------------------------------+
| backup_freshness   | PASSED  | Latest backup is fresh (2.4 hours old).                   |
| archive_integrity  | PASSED  | Archive file exists and cryptographic SHA-256 verified.   |
| size_anomaly       | PASSED  | Backup size is within normal historical parameters.       |
| recent_runs        | PASSED  | Recent backup runs completed without error.               |
+--------------------+---------+-----------------------------------------------------------+
All backup health checks passed.
```

If the latest backup is older than `health.max_age_hours` (default 28 hours) or fails cryptographic checksum validation, the command exits with code `1` (useful for Prometheus, Nagios, or cron monitoring).

---

## 4. Pruning Expired Backups

The retention policy runs automatically after every backup, retaining the most recent `retention.limit` successful backups. You can also run it on demand:

```bash
php artisan scoped-backup:prune --retention=7
```

You can optionally filter by backup type:

```bash
php artisan scoped-backup:prune --retention=10 --type=scoped
```

---

## 5. Previewing and Restoring

### Pre-flight Archive Inspection (Full Backup)

Verify archive TOC without performing a restore:

```bash
php artisan scoped-backup:restore 1 --type=full --verify-only
```

### Dry-Run Restoration (Scoped Backup)

Simulate scoped dataset restoration to view what records would be created or updated without modifying the database:

```bash
php artisan scoped-backup:restore storage/app/backups/scoped_users.zip --dry-run
```

### Performing a Scoped Dataset Restore

```bash
php artisan scoped-backup:restore storage/app/backups/scoped_users.zip
```

### Performing a Full Database Restore

Full restores are destructive and require explicit confirmation:

```bash
# In production, require --force or interactive confirmation:
php artisan scoped-backup:restore 1 --type=full --force
```

---

## Next Steps

- Explore [Full Backups](full-backups.md) for details on archive formats and table exclusions.
- Explore [Scoped Backups](scoped-backups.md) to learn how identity keys prevent duplicate rows.
- Review [Restoration](restoration.md) for disaster recovery procedures and production safeguards.
