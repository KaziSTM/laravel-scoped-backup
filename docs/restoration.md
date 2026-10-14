# Restoration & Disaster Recovery

The package provides two distinct restoration pipelines: full database restoration and scoped dataset restoration.

---

## 1. Full Database Restoration

Full database restoration extracts the raw database dump from a `.zip` archive and executes native restore tooling against the target connection.

### Safety Guards & Production Protections

- **Production Environment Gate:** If `app.env` is `production`, full restoration is blocked by default with a `SecurityException`.
- To restore in production, you must either:
  1. Specify an isolated target connection (e.g. `--connection=recovery_db`).
  2. Provide the explicit `--force` CLI option or accept the interactive CLI warning prompt.
- **Pre-flight Archive Verification:** Before touching the target database, the package verifies:
  1. Cryptographic SHA-256 checksum of the internal database dump against `manifest.json`.
  2. Table of Contents (TOC) inspection using engine tooling (`pg_restore --list` for PostgreSQL, SQL header/trailer parsing for MySQL/MariaDB, `PRAGMA integrity_check` for SQLite).

### Full Restore Commands

```bash
# Pre-flight verify without executing restore
php artisan scoped-backup:restore 1 --type=full --verify-only

# Restore into a dedicated recovery connection
php artisan scoped-backup:restore 1 --type=full --connection=pgsql_recovery

# Destructive restore in production (requires explicit --force)
php artisan scoped-backup:restore 1 --type=full --force

# Restore database and restore included filesystem assets into storage/app
php artisan scoped-backup:restore 1 --type=full --restore-files
```

### Full Restore Programmatic API

```php
use KaziSTM\LaravelScopedBackup\Actions\RestoreFullBackupAction;

$action = app(RestoreFullBackupAction::class);

$result = $action->execute(
    backup: 1, // BackupRecord ID, model instance, or path string
    targetConnection: 'pgsql_recovery',
    force: false,
    verifyOnly: false,
    restoreFiles: false,
);
```

---

## 2. Scoped Dataset Restoration

Scoped restoration imports JSON Lines records from a scoped backup archive into Eloquent models.

> [!NOTE]
> **Root-Model Scope in v1.0:**
> Scoped dataset restoration in v1.0 imports and upserts root-model records. Eager-loaded child relationships and supplementary entities (`entities/`) serialized during export are preserved in the archive for inspection, auditing, and custom extraction, but are not automatically cascade-inserted or foreign-key remapped into child tables by `ScopedImporter`.

### Natural-Key Upsert Semantics

Restoration resolves existing records by matching the `identity_keys` declared in the backup manifest or overridden in options:

1. **Existing Record Matched:**
   - Evaluates existing model instance using `forceFill($attributes)`.
   - If dirty, updates and saves the record (`recordsUpdated++`).
   - If attributes are identical, avoids unnecessary write queries (`recordsUnchanged++`).
2. **No Record Matched:**
   - Creates a new model instance using `newInstance([], false)`.
   - Applies attributes via `forceFill($attributes)` to bypass mass-assignment guards.
   - Saves record to target connection (`recordsCreated++`).
3. **PostgreSQL Sequence Synchronization:**
   - In PostgreSQL, inserting records with explicit integer IDs does not advance the underlying sequence. The importer automatically updates `pg_get_serial_sequence` to `MAX(id)` post-restore, preventing primary key collisions on subsequent application inserts.

### Dry-Run Restoration (`--dry-run`)

Simulate restoration without persisting changes:

```bash
php artisan scoped-backup:restore storage/app/backups/scoped_users.zip --dry-run
```

> [!WARNING]
> **Dry-Run Guarantee Disclaimer:**
> A dry run evaluates JSON formatting, resolves target models, and counts matches against current database rows. However, a successful dry run **does not guarantee** that a live restore will succeed under concurrent writes or unmodeled database-level foreign key constraints.

---

## 3. SQLite Atomic Staging & WAL Recovery Mechanics

SQLite restores operate on physical database files. To prevent database corruption or partial writes if a restore fails midway:

1. **Pre-Restore Checkpoint:** If the target database exists, `SqliteDriver` attempts a `PRAGMA wal_checkpoint(TRUNCATE)` to flush uncommitted frames and fails early if an exclusive lock is held by another process.
2. **Atomic Staging:** Restoration is written to an isolated staging file (`<database>.restoring_*`), followed by verification via `PRAGMA quick_check`.
3. **Rollback Snapshot:** Prior to file replacement, a rollback copy of the main database and `-wal` file is created.
4. **Atomic Swap:** The verified staged file is atomically swapped into place via `rename()`.
5. **Sidecar Clearance (No SHM Reuse):** Stale `-wal` and `-shm` files are removed so the restored database starts clean. Note that `-shm` shared memory files are intentionally never copied or restored, as SQLite regenerates shared memory on new connections.
6. **Failure Recovery:** If the swap fails, the original database and WAL file are restored from the rollback snapshot.

### Open-Connection Inode Behavior & Operational Sequence

On POSIX operating systems, `rename()` swaps the directory pointer to the restored file's new inode. However, **any process with a database connection already open continues holding the old file descriptor**.

To ensure all processes interact with the restored database:
1. **Quiesce Application:** Place application in maintenance mode (`php artisan down`) and terminate background queue workers (`php artisan queue:restart`).
2. **Execute Restore:** Run the restore command (`php artisan scoped-backup:restore <backup-id> --type=full`).
3. **Verify Integrity:** Run health checks (`php artisan scoped-backup:monitor`).
4. **Resume Operations:** Restart workers and bring the application out of maintenance mode (`php artisan up`). Existing worker processes reconnect to the restored database inode.

---

## Disaster Recovery Checklist

In a disaster recovery scenario:

1. **Verify Storage Access:**
   Ensure backup archives on your storage disk (e.g. S3) are accessible.
2. **Create Fresh Target Database:**
   Avoid restoring over a corrupt database directly. Create an isolated database:
   ```bash
   createdb app_recovery
   ```
3. **Configure Recovery Connection:**
   Add a temporary connection `pgsql_recovery` in `config/database.php`.
4. **Execute Verified Restore:**
   ```bash
   php artisan scoped-backup:restore <backup-id> --type=full --connection=pgsql_recovery
   ```
5. **Verify Application State:**
   Inspect tables and run health smoke tests before repointing production traffic.
