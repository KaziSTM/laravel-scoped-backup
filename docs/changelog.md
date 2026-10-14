# Changelog

All notable changes to `kazistm/laravel-scoped-backup` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] - 2026-10-14

### Added
- **Multi-Driver Database Backup & Restore**:
  - PostgreSQL driver using `pg_dump` and `pg_restore` / `psql` with `--clean --if-exists`, custom binary path configuration, SSL modes, and multi-version compatibility.
  - MySQL driver using `mysqldump` and `mysql` with configurable routines, triggers, events, and single-transaction flags.
  - MariaDB support via native `mariadb-dump` / `mariadb` utilities with fallback to `mysqldump` and dynamic option handling (`--skip-events` detection).
  - SQLite driver using native connection VACUUM INTO, fallback dump routines, busy timeout configuration, and WAL (`-wal` / `-shm`) journal frame isolation during restoration.
- **Scoped Eloquent Dataset Backups**:
  - Declarative dataset definitions (`ScopedBackupDefinition`) supporting Eloquent models, custom base query constraints, eager-loaded relationships, and additional dependent entity collections.
  - Streaming JSON Lines (`.jsonl`) export and import with configurable chunk sizes.
  - Natural key and primary key identity resolution with model upsert semantics during restoration.
  - Model event dispatch control (`withoutEvents`) during restoration.
- **Enterprise Storage & Archive Management**:
  - ZIP archive generation with standard manifest files (`manifest.json`) containing cryptographic checksums (SHA-256), schema versions, database metadata, and file listings.
  - Configurable inclusion of application filesystem paths and storage disks.
  - Full support for local and remote Laravel filesystems (AWS S3, MinIO, Cloudflare R2, Google Cloud Storage, SFTP) with stream-based SHA-256 validation and chunked temp-file restoration.
- **Lifecycle & Operational Automation**:
  - Flexible retention policies supporting days-based (`retention_days`) or count-based (`keep_last`) pruning.
  - Automated health monitoring checking for stale backups, storage size anomalies, record count shifts, and corrupted archive integrity.
  - Multi-channel notification dispatcher supporting Email (`MailMessage`) and Discord/Slack Webhooks.
  - Rich Artisan CLI suite:
    - `backup:run` (Full database and filesystem backup)
    - `backup:scoped` (Scoped Eloquent dataset backup)
    - `backup:restore` (Full and scoped restoration with dry-run support)
    - `backup:list` (Tabular listing with status and disk info)
    - `backup:prune` (Automated cleanup of expired archives)
    - `backup:monitor` (Health and integrity diagnostics with exit codes)
- **Security & Integrity Hardening**:
  - Zip Slip path traversal mitigation with strict directory containment verification.
  - Restricted archive and temporary extraction directory permissions (`0700`).
  - Production restoration guards requiring explicit `--force` confirmation.
  - Temporary credential masking in process execution and command logging.
  - Post-restore PostgreSQL sequence resynchronization (`syncPostgresSequence`).

### Fixed
- **Scoped Restoration Attribute Guarding**: Replaced `fill()` with `forceFill()` during scoped model restoration, preventing mass-assignment guards (`$fillable` / `$guarded`) from silently dropping primary keys (`id`), passwords, and foreign keys.
- **SQLite Database Corruption on Restore**: Fixed WAL journal file collisions by isolating and removing existing `.db-wal` and `.db-shm` files before restoring from dump, restoring WAL backups safely on failure.
- **Production Restore Confirmation Bug**: Fixed `BackupRestoreCommand` where answering "yes" to interactive production confirmation failed to execute due to unassigned force flag.
- **MariaDB Option Warnings**: Dynamic driver key resolution prevents MariaDB from receiving incompatible MySQL options (`--skip-events`). Added auto-detection via `is_mariadb` and `Connection::isMaria()`.
- **Remote Cloud Storage Compatibility**: Implemented stream-based hashing in `ChecksumValidator` and temp-file download streaming in `RestoreFullBackupAction`, enabling complete operations on disks that do not provide local file paths.
- **Scoped Exporter Transaction Isolation**: Fixed transaction isolation level setting to execute inside the transaction closure on the model's actual connection.
- **Notification Event Key Alignment**: Fixed `BackupNotificationDispatcher` to handle `restore_failed` events properly alongside `backup_failed`.
- **Decoupled Test Suite**: Resolved test dependencies on parent application classes, introducing standalone `tests/TestCase.php` with Orchestra Testbench and in-memory test fixtures.

---

## [0.1.0] - 2026-09-01

### Added
- Initial extraction of scoped backup functionality from Company Radar.
- PostgreSQL full database backup and restore action prototype.
- Scoped dataset exporter and importer prototype.
- Basic Artisan commands for backup and restore.
- Initial database migration for backup records.
