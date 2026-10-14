# Database Drivers & Engine Comparison

The package includes native driver implementations for PostgreSQL, MySQL, MariaDB, and SQLite.

---

## Driver Comparison Table

| Feature / Detail | PostgreSQL (`pgsql`) | MySQL (`mysql`) | MariaDB (`mariadb`) | SQLite (`sqlite`) |
| :--- | :--- | :--- | :--- | :--- |
| **Backup Tool** | `pg_dump` | `mysqldump` | `mariadb-dump` / `mysqldump` | Native `SQLite3::backup()` |
| **Restore Tool** | `pg_restore` | `mysql` | `mariadb` / `mysql` | Native `SQLite3::backup()` |
| **Archive Format** | Custom compressed binary (`-F c`) | SQL script (`.sql`) | SQL script (`.sql`) | SQLite database (`.sqlite`) |
| **External CLI Binary** | Required (`pg_dump`, `pg_restore`) | Required (`mysqldump`, `mysql`) | Required (`mariadb-dump`, `mariadb`) | None (Uses PHP `ext-sqlite3`) |
| **Consistency Strategy** | `REPEATABLE READ` snapshot | `--single-transaction --quick` | `--single-transaction --quick` | Online C-level page lock merging WAL |
| **Supported Objects** | Tables, Views, Sequences, Functions, Triggers, Types | Tables, Views, Routines, Triggers, Events | Tables, Views, Routines, Triggers, Events | Tables, Views, Triggers, Indexes |
| **Pre-flight Verification** | `pg_restore --list` (TOC parsing) | Header & completion trailer parsing | Header & completion trailer parsing | `PRAGMA integrity_check` & `PRAGMA quick_check` |
| **Lock Handling** | Non-blocking via MVCC | Non-blocking via InnoDB MVCC | Non-blocking via InnoDB MVCC | Configurable `busy_timeout` (default 5s) |
| **Minimum Version** | PostgreSQL 12+ | MySQL 5.7+ / 8.0+ / 8.4+ | MariaDB 10.3+ | SQLite 3.27+ |
| **Integration Tested** | Unit + End-to-end verified | Unit + CLI verified | Unit + CLI verified | Fully integration tested (Unit & Feature) |

---

## Engine Deep Dives

### 1. PostgreSQL Driver (`PostgreSqlDriver`)

- **Backup Command:**
  ```bash
  pg_dump -h <host> -p <port> -U <user> -d <database> -F c -f <output>
  ```
- **Restore Command:**
  ```bash
  pg_restore -h <host> -p <port> -U <user> -d <database> --clean --if-exists <archive>
  ```
- **Security:**
  `PGPASSWORD` is set in the process environment array. It is never interpolated into the shell command string and is redacted from all logs and error messages.
- **Verification:**
  Validates that `pg_restore --list` produces a non-empty TOC listing tables and sequences.

### 2. MySQL Driver (`MySqlDriver`)

- **Backup Command:**
  ```bash
  mysqldump --host=<host> --port=<port> --user=<user> --single-transaction --quick --skip-lock-tables --routines --triggers --events --default-character-set=utf8mb4 --result-file=<output> <database>
  ```
- **Restore Command:**
  ```bash
  mysql --host=<host> --port=<port> --user=<user> --default-character-set=utf8mb4 <database>
  ```
  The SQL file is streamed via stdin into the `mysql` process.
- **Security:**
  Password is passed exclusively via the `MYSQL_PWD` environment variable.

### 3. MariaDB Driver (`MariaDbDriver`)

- Extends `MySqlDriver` with MariaDB binary resolution:
  1. Checks for `mariadb-dump` and `mariadb`.
  2. If not found and `fallback_to_mysql` is enabled, falls back to `mysqldump` and `mysql`.
- **Automatic MariaDB Detection:**
  In Laravel applications where the database connection driver is defined as `'mysql'` while connecting to a MariaDB server:
  - If `is_mariadb => true` is declared in connection config, or
  - If the active connection returns true for `$connection->isMaria()`,
  `DatabaseBackupDriverManager` automatically resolves `MariaDbDriver`.

### 4. SQLite Driver (`SqliteDriver`)

- **No CLI Binaries Required:** Operates entirely through PHP's `SQLite3` extension.
- **WAL & Active Connection Support:**
  Executes `SQLite3::backup()`, which steps through pages while holding a shared lock. Transactions committed during the backup are merged from the Write-Ahead Log (`-wal`).
- **Memory Databases:** In-memory SQLite databases (`:memory:`) cannot be backed up to disk and are rejected with a clear exception.
- **Atomic Staging & Rollback Safeguards:**
  Before restoring, `SqliteDriver` checkpoints active transactions (`PRAGMA wal_checkpoint(TRUNCATE)`), stages the restore into a temporary file in the target directory (`.restoring_*`), creates a rollback copy of the target database and WAL file (`.pre_restore_bak_*`), and atomically swaps the staged database via `rename()`.
- **Open-Connection Behavior & Operational Requirement:**
  On POSIX systems (Linux, macOS), replacing an SQLite database file via `rename()` updates the directory entry to point to the new inode. However, **already-open connections continue reading/writing to the old file descriptor and inode until closed**.
  - **Required Operational Sequence:**
    1. Quiesce application workers, queue listeners, and HTTP traffic (`php artisan down`).
    2. Close active database connections or restart worker processes.
    3. Execute the restoration command (`php artisan scoped-backup:restore`).
    4. Reconnect or restart application workers to acquire handles to the new database file.
    5. Verify integrity (`PRAGMA quick_check`) and resume normal traffic (`php artisan up`).
- **Operating System Compatibility:**
  - *Linux & macOS:* Atomic swap via same-directory `rename()` supported natively.
  - *Windows (NTFS):* Windows locks active file handles, blocking file replacement while open connections exist. Full application shutdown is mandatory on Windows.

---

## Extending and Registering Custom Drivers

You can register custom database drivers (e.g. CockroachDB, SQL Server) using `DatabaseBackupDriverManager::extend()`:

```php
use KaziSTM\LaravelScopedBackup\Database\DatabaseBackupDriverManager;

app(DatabaseBackupDriverManager::class)->extend('sqlsrv', function () {
    return new MyCustomSqlServerDriver();
});
```

Custom drivers must implement `KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver`.
