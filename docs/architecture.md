# Architecture & Design

`kazistm/laravel-scoped-backup` is architected as an enterprise-grade disaster recovery and dataset serialization engine for Laravel applications.

---

## Architectural Diagram

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│                          Application Layer / CLI                            │
│  scoped-backup:full | scoped-backup:scoped | :restore | :prune | :monitor   │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Action Layer (Single Purpose)                        │
│  CreateFullBackupAction     RestoreFullBackupAction     PruneBackupsAction  │
│  CreateScopedBackupAction   RestoreScopedBackupAction   VerifyHealthAction  │
└──────────────┬───────────────────────────────────────────────┬──────────────┘
               │                                               │
               ▼                                               ▼
┌──────────────────────────────┐               ┌──────────────────────────────┐
│     Full Backup Engine       │               │    Scoped Dataset Engine     │
│  DatabaseBackupDriverManager │               │  ScopedBackupDefinition      │
│  ├── PostgreSqlDriver        │               │  ScopedExporter (JSONL)      │
│  ├── MySqlDriver             │               │  ScopedImporter (Upsert)     │
│  ├── MariaDbDriver           │               │  Model Connection Resolver   │
│  └── SqliteDriver            │               └──────────────┬───────────────┘
└──────────────┬───────────────┘                              │
               │                                              │
               └──────────────────────┬───────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                             Core Services                                   │
│  ArchiveManager        ChecksumValidator       BackupLockManager            │
│  (ZIP, Zip Slip safe)  (Streaming SHA-256)     (Execution & Scope Locks)    │
└─────────────────────────────────────┬───────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Infrastructure Layer                              │
│  Flysystem Storage Disks (Local / S3) │ Database Tracking (BackupRecord)    │
│  Event System (Lifecycle Events)      │ Notification Dispatcher (Mail/Webhk)│
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Key Architectural Decisions

### 1. Dual Backup Strategies
The package explicitly divides backup responsibilities into two distinct, non-overlapping domains:
- **Full Database Backups:** Heavyweight, engine-native, byte-level snapshots intended for disaster recovery and staging replication.
- **Scoped Dataset Snapshots:** Lightweight, portable, schema-agnostic JSON Lines models intended for tenancy exports, reference data versioning, and test fixture generation.

### 2. Action-Driven Architecture
Rather than concentrating logic into monolithic service classes, business processes are split into focused, single-responsibility action classes (`CreateFullBackupAction`, `RestoreScopedBackupAction`, etc.). This promotes testability, reusability in queue jobs, and simple dependency injection.

### 3. Driver Abstraction Layer
The database driver subsystem is modeled around the `DatabaseBackupDriver` interface and managed by `DatabaseBackupDriverManager`. Each engine encapsulates:
- CLI binary or PHP extension discovery.
- Safe process argument generation with credential isolation.
- Engine-specific archive verification (TOC parsing, SQL trailer inspection, SQLite pragma checks).
- Engine-specific restoration and rollback handling.

### 4. Streaming & Memory Efficiency
- Scoped models are queried in deterministic primary-key chunks (`chunk()`) and written immediately to an unbuffered file stream (`records.jsonl`).
- ZIP archives are created directly from staging files without loading entire archives into PHP memory.
- Uploads and downloads across Flysystem storage disks (including remote S3/GCS buckets) operate via readable PHP stream resources.

### 5. Audit-Preserving Retention
Retention pruning removes physical files from storage while keeping tracking rows in `database_backups` with a timestamped `pruned_at` column. This provides historical metrics on database growth, durations, and backup frequency over time.
