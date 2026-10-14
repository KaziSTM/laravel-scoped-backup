# Troubleshooting & Diagnostics

This guide provides diagnostic procedures and solutions for common operational issues.

---

## Diagnostic Commands

### 1. Evaluate Overall System Health
```bash
php artisan scoped-backup:monitor
```
Reports freshness, archive integrity, size anomalies, and recent run errors.

### 2. Inspect Database Tracking Records
Check the most recent backup attempts in your database:

```sql
SELECT id, status, trigger, driver, database_name, duration_seconds, error_message, created_at 
FROM database_backups 
ORDER BY id DESC 
LIMIT 10;
```

---

## Common Issues & Solutions

### 1. `pg_dump executable not found or not executable at [pg_dump]`

**Cause:** The PostgreSQL client utility is not installed on the system or is not located in the system `$PATH`.

**Solution:**
- **Ubuntu/Debian:** Install `postgresql-client`:
  ```bash
  sudo apt-get install postgresql-client
  ```
- **Alpine Linux / Containers:**
  ```bash
  apk add postgresql-client
  ```
- **Custom Path:** If installed in a non-standard location, set `PG_DUMP_BINARY` in `.env`:
  ```dotenv
  PG_DUMP_BINARY=/usr/lib/postgresql/16/bin/pg_dump
  PG_RESTORE_BINARY=/usr/lib/postgresql/16/bin/pg_restore
  ```

---

### 2. `mysqldump process failed with exit code 2: Access denied`

**Cause:** The database user does not have sufficient privileges to perform a dump with routines, triggers, or events.

**Solution:**
- Verify credentials in `config/database.php` or `.env`.
- Grant the required backup privileges:
  ```sql
  GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON *.* TO 'app_user'@'%';
  ```
- If your database host restricts stored routines or events (e.g. managed cloud databases without SUPER/PROCESS privileges), disable them in `config/scoped-backup.php`:
  ```php
  'mysql' => [
      'routines' => false,
      'events' => false,
  ],
  ```

---

### 3. `SQLite backup failed: database is locked` (`SQLITE_BUSY`)

**Cause:** Concurrent database transactions held a write lock longer than the configured busy timeout.

**Solution:**
- Increase `busy_timeout` in `config/scoped-backup.php`:
  ```php
  'sqlite' => [
      'busy_timeout' => 10000, // 10 seconds
  ],
  ```
- Ensure your SQLite database has WAL mode enabled for concurrent reads and writes:
  ```sql
  PRAGMA journal_mode = WAL;
  ```

---

### 4. `ProcessTimedOutException: The process exceeded the timeout of 600 seconds`

**Cause:** Large databases or slow I/O exceeded the configured process timeout limit.

**Solution:**
Increase `BACKUP_TIMEOUT` in `.env`:
```dotenv
BACKUP_TIMEOUT=1800 # 30 minutes
```

---

### 5. `BackupVerificationException: Database dump checksum mismatch`

**Cause:** The cryptographic SHA-256 hash of the extracted dump file does not match the hash recorded in `manifest.json`.

**Implications:**
- The archive may have been truncated during an interrupted upload.
- Underlying storage experienced bit rot or data corruption.
- Do not attempt to restore this archive into production.

---

### 6. `SecurityException: Destructive full database restore into production is blocked`

**Cause:** A full database restore was attempted in an environment where `APP_ENV=production` without passing `--force`.

**Solution:**
- To restore in production, pass the explicit `--force` flag:
  ```bash
  php artisan scoped-backup:restore 42 --type=full --force
  ```
- Recommended best practice: Restore into a staging or recovery database instead:
  ```bash
  php artisan scoped-backup:restore 42 --type=full --connection=pgsql_recovery
  ```

---

### 7. Memory Limit Exceeded During Scoped Backup

**Cause:** The scoped model definition is processing very large records or relations in chunks that exceed PHP's memory limit.

**Solution:**
- Reduce the chunk size on the command or definition:
  ```bash
  php artisan scoped-backup:scoped "App\Models\Order" --chunk=25
  ```
- In code, call `->chunkSize(25)` on `ScopedBackupDefinition`.
