# Full Database & Filesystem Backups

Full backups provide byte-accurate, complete snapshots of your database and optional local application filesystem assets.

---

## Architecture & Lifecycle

When a full backup is initiated via `BackupFullCommand` or `CreateFullBackupAction`, the following lifecycle executes:

```text
[ Acquire Execution Lock ] (Prevents concurrent backup operations)
         │
         ▼
[ Create Tracking Record ] (Records status as RUNNING in database_backups)
         │
         ▼
[ Execute Database Dump ] (Streams native database dump to temporary staging file)
         │
         ▼
[ Verify Dump Artifact ] (Pre-publication check: TOC validation / SQLite integrity)
         │
         ▼
[ Package ZIP Archive ] (Assembles dump, manifest.json, and optional files)
         │
         ▼
[ Compute SHA-256 ] (Calculates cryptographic checksums)
         │
         ▼
[ Stream to Storage Disk ] (Uploads ZIP to target local or remote Flysystem disk)
         │
         ▼
[ Update Tracking Record ] (Sets status to SUCCEEDED with duration, size, and metadata)
         │
         ▼
[ Enforce Retention Policy ] (Prunes older backups while keeping last known good)
         │
         ▼
[ Release Execution Lock ]
```

---

## Database Engine Handlers

### PostgreSQL
- **Dump Utility:** `pg_dump`
- **Format:** Custom binary format (`-F c`). Custom format is compressed, permits parallel restores, and embeds a Table of Contents (TOC).
- **Security:** Credentials (`password`) are passed strictly via the `PGPASSWORD` environment variable, avoiding exposure in `/proc` or `ps` CLI tables.
- **Verification:** Runs `pg_restore --list` against the staging file before packaging to verify non-empty TOC entries.

### MySQL & MariaDB
- **Dump Utility:** `mysqldump` (or `mariadb-dump` with automatic fallback to `mysqldump`).
- **Consistency:** Uses `--single-transaction` for consistent InnoDB read snapshots without holding global read locks.
- **Large Dataset Streaming:** Uses `--quick` to stream rows row-by-row instead of buffering full tables into RAM.
- **Object Support:** Captures stored routines (`--routines`), triggers (`--triggers`), and event scheduler definitions (`--events`).
- **Security:** Password passed via `MYSQL_PWD` environment variable.
- **Verification:** Validates standard SQL header markers (`MySQL dump`, `MariaDB dump`, `/*!40101`) and completion trailer (`Dump completed on`, `SET SQL_NOTES`).

### SQLite
- **Backup Mechanism:** Native `SQLite3::backup()` online backup API.
- **Consistency:** Captures active database pages while properly merging uncommitted or checkpointed WAL pages (`-wal`).
- **Locks:** Employs configurable busy timeout (`busy_timeout: 5000` ms) to handle active transactions without `SQLITE_BUSY` crashes.
- **Verification:** Runs `PRAGMA integrity_check;` and `PRAGMA quick_check;` on the generated file to ensure structural integrity.

---

## Filesystem Inclusion & Exclusions

Full backups can optionally package local files and directories (e.g. uploaded avatars, documents, public media) alongside the database dump.

### Configuration

In `config/scoped-backup.php`:

```php
'filesystem' => [
    'enabled' => env('BACKUP_INCLUDE_FILES', false),
    'include_paths' => [
        storage_path('app/public'),
        storage_path('app/documents'),
    ],
    'exclude_paths' => [
        '*.tmp',
        'cache/*',
        '.git*',
    ],
],
```

### CLI Flag

Pass `--include-files` to enable filesystem inclusion for a specific run:

```bash
php artisan scoped-backup:full --include-files
```

---

## Archive Layout

Full backup archives are standard ZIP files structured as follows:

```text
my_database_2026-10-14_140000_a1b2c3d4.zip
├── manifest.json            # Machine-readable metadata, checksums, and environment info
├── database.dump            # Raw database dump artifact (binary or SQL)
└── files/                   # Included filesystem directories (optional)
    ├── public/
    │   └── uploads/
    └── documents/
```

### Manifest Format (`manifest.json`)

```json
{
    "schema_version": "1.0",
    "backup_type": "full",
    "created_at": "2026-10-14T14:00:02+01:00",
    "environment": "production",
    "driver": "pgsql",
    "database_name": "app_production",
    "counts": {
        "toc_entries": 312,
        "files": 45
    },
    "checksums": {
        "database_dump_sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"
    },
    "metadata": {
        "format": "custom",
        "compression": true,
        "include_files": true,
        "laravel_version": "11.41.0"
    }
}
```

---

## Programmatic Execution

Use `CreateFullBackupAction` to execute full backups in custom jobs, controllers, or artisan tasks:

```php
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;

class BackupJob
{
    public function handle(CreateFullBackupAction $createBackup): void
    {
        $record = $createBackup->execute(
            trigger: BackupTrigger::SCHEDULED,
            connection: 'pgsql',
            disk: 's3',
            path: 'daily-backups',
            retentionLimit: 14,
            includeFiles: true,
        );

        Log::info("Backup complete: {$record->file_path} ({$record->file_size_bytes} bytes)");
    }
}
```

---

## Remote Storage Considerations (S3, GCS, Azure)

- The package stages archives locally in `storage.temp_staging_dir` before streaming them to destination disks using Flysystem streams (`Storage::disk()->put($path, $stream)`).
- Staging files are cleaned up immediately following successful upload or upon any caught exception.
- When restoring from remote disks, `RestoreFullBackupAction` automatically streams the remote archive to a temporary local staging directory for extraction and TOC verification.
