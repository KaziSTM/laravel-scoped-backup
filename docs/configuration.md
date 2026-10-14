# Configuration Reference

The package configuration is stored in `config/scoped-backup.php`. Every setting can be tuned using environment variables or overridden directly in the published file.

---

## Complete Reference

### Tracking Table & Model

| Option | Environment Variable | Default | Purpose & Implications |
| :--- | :--- | :--- | :--- |
| `table_name` | `BACKUP_TABLE_NAME` | `'database_backups'` | The database table where backup execution logs, durations, SHA-256 checksums, and pruning status are recorded. |
| `model` | N/A | `\KaziSTM\LaravelScopedBackup\Models\BackupRecord::class` | The Eloquent model class used to query and mutate tracking records. |

---

### Database Connection & Driver Options

#### Connection & Process Limits

| Option | Environment Variable | Default | Purpose & Implications |
| :--- | :--- | :--- | :--- |
| `database.connection` | `BACKUP_DATABASE_CONNECTION` | `config('database.default', 'pgsql')` | Name of the database connection (from `config/database.php`) used as the source for full backups. |
| `database.timeout` | `BACKUP_TIMEOUT` | `600` (seconds) | Maximum execution time in seconds for external dump and restore process executions. |

#### PostgreSQL Driver (`database.drivers.pgsql`)

| Option | Environment Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `dump_binary` | `PG_DUMP_BINARY` | `'pg_dump'` | Path or binary name for PostgreSQL dump executable. |
| `restore_binary` | `PG_RESTORE_BINARY` | `'pg_restore'` | Path or binary name for PostgreSQL restore executable. |
| `format` | N/A | `'custom'` | Output format passed via `-F c` to produce compressed, restorable binary dumps. |
| `pre_publication_verify` | N/A | `true` | When true, runs `pg_restore --list` to verify TOC integrity before creating the final ZIP package. |

#### MySQL Driver (`database.drivers.mysql`)

| Option | Environment Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `dump_binary` | `MYSQLDUMP_BINARY` | `'mysqldump'` | Path or binary name for MySQL dump tool. |
| `restore_binary` | `MYSQL_BINARY` | `'mysql'` | Path or binary name for MySQL CLI client. |
| `single_transaction` | N/A | `true` | Pass `--single-transaction` for consistent InnoDB snapshots without table locking. |
| `quick` | N/A | `true` | Pass `--quick` to stream rows row-by-row instead of buffering full tables into RAM. |
| `routines` | N/A | `true` | Include stored procedures and functions (`--routines`). |
| `triggers` | N/A | `true` | Include triggers (`--triggers`). |
| `events` | N/A | `true` | Include event scheduler jobs (`--events`). |
| `default_character_set` | N/A | `'utf8mb4'` | Enforces character encoding during dump generation. |

#### MariaDB Driver (`database.drivers.mariadb`)

| Option | Environment Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `dump_binary` | `MARIADB_DUMP_BINARY` | `'mariadb-dump'` | Path or binary name for MariaDB dump tool. |
| `restore_binary` | `MARIADB_BINARY` | `'mariadb'` | Path or binary name for MariaDB client. |
| `fallback_to_mysql` | N/A | `true` | When `mariadb-dump` is not executable, automatically fall back to `mysqldump` and `mysql`. |
| `single_transaction` | N/A | `true` | Pass `--single-transaction` for consistent snapshot creation. |
| `quick` | N/A | `true` | Stream rows without buffering full tables. |
| `routines` | N/A | `true` | Include stored procedures and routines. |
| `triggers` | N/A | `true` | Include table triggers. |
| `events` | N/A | `true` | Include events. |
| `default_character_set` | N/A | `'utf8mb4'` | Enforces charset consistency. |

#### SQLite Driver (`database.drivers.sqlite`)

| Option | Environment Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `busy_timeout` | N/A | `5000` (ms) | Milliseconds to wait before failing with `SQLITE_BUSY` when locks are held by concurrent processes. |
| `use_native_backup` | N/A | `true` | Uses PHP's native `SQLite3::backup()` C-level online backup API, properly merging active WAL frames. |

---

### Filesystem Inclusion

| Option | Environment Variable | Default | Purpose & Implications |
| :--- | :--- | :--- | :--- |
| `filesystem.enabled` | `BACKUP_INCLUDE_FILES` | `false` | Whether full backups should include local directories by default. |
| `filesystem.include_paths` | N/A | `[]` | Array of absolute directory paths to package into the `files/` folder inside the backup archive. |
| `filesystem.exclude_paths` | N/A | `['*.tmp', '.git*']` | Array of glob patterns to exclude from included directories. |

---

### Storage Settings

| Option | Environment Variable | Default | Purpose & Implications |
| :--- | :--- | :--- | :--- |
| `storage.disk` | `BACKUP_STORAGE_DISK` | `'local'` | Laravel storage disk configured in `config/filesystems.php` (e.g. `local`, `s3`, `backup_disk`). |
| `storage.path` | `BACKUP_STORAGE_PATH` | `'backups'` | Relative subdirectory path on the storage disk where `.zip` backup archives will be stored. |
| `storage.temp_staging_dir` | N/A | `storage_path('framework/cache/backups')` | Local filesystem path used for temporary staging during archive creation and restoration. |

---

### Retention Policy

| Option | Environment Variable | Default | Purpose & Implications |
| :--- | :--- | :--- | :--- |
| `retention.limit` | `BACKUP_RETENTION` | `7` | Number of most recent successful backups to retain before pruning older files. |
| `retention.protect_last_successful` | N/A | `true` | Failsafe ensuring that the single remaining successful backup is never deleted, even if limit is exceeded. |
| `retention.preserve_audit_records` | N/A | `true` | When true, pruning only removes physical files from storage and timestamps `pruned_at` in the DB for audit history. |

---

### Scoped Dataset Settings

| Option | Environment Variable | Default | Purpose & Implications |
| :--- | :--- | :--- | :--- |
| `scoped.chunk_size` | `BACKUP_SCOPED_CHUNK_SIZE` | `100` | Default number of records per query chunk during streaming JSON Lines export. |
| `scoped.storage_path` | `BACKUP_SCOPED_STORAGE_PATH` | `database_path('seeders/data/datasets')` | Default destination directory for seeders or repository-committed export datasets. |

---

### Health Monitoring & TTL Thresholds

| Option | Environment Variable | Default | Purpose & Implications |
| :--- | :--- | :--- | :--- |
| `health.max_age_hours` | `BACKUP_MAX_AGE_HOURS` | `28` | Maximum allowable hours since the last successful backup before triggering a stale backup alert. |
| `health.size_deviation_threshold_percent` | N/A | `50` (%) | Percentage drop in archive size compared to the 5-run historical average that triggers an anomaly warning. |

---

### Notifications

| Option | Environment Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `notifications.enabled` | `BACKUP_NOTIFICATIONS_ENABLED` | `false` | Master toggle for notification dispatch. |
| `notifications.channels` | N/A | `['mail', 'webhook']` | Enabled notification delivery channels. |
| `notifications.mail.to` | `BACKUP_NOTIFICATION_EMAIL` | `null` | Destination email address for backup alert notifications. |
| `notifications.webhook.url` | `BACKUP_DISCORD_WEBHOOK_URL` | `null` | Webhook HTTP endpoint URL. |
| `notifications.webhook.format` | N/A | `'discord'` | Payload schema: `'discord'` (rich embeds) or `'generic_json'`. |
| `notifications.events.backup_failed` | N/A | `true` | Notify on backup process failures. |
| `notifications.events.unhealthy_detected` | N/A | `true` | Notify on stale or failed health checks. |
| `notifications.events.backup_succeeded` | N/A | `false` | Notify on every successful backup run (disabled by default to prevent alert fatigue). |

---

### Scheduler Settings

| Option | Environment Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `schedule.enabled` | N/A | `false` | Informational setting indicating whether automated scheduling is active. |
| `schedule.time` | `BACKUP_SCHEDULE_TIME` | `'01:00'` | Scheduled daily execution time (e.g. `'01:00'`). |

---

## Environment Variable Cheat Sheet

Add these keys to your `.env` file as needed:

```dotenv
# Storage & Retention
BACKUP_STORAGE_DISK=s3
BACKUP_STORAGE_PATH=company_backups
BACKUP_RETENTION=14
BACKUP_TIMEOUT=900

# Database Connection
BACKUP_DATABASE_CONNECTION=pgsql
PG_DUMP_BINARY=/usr/bin/pg_dump
PG_RESTORE_BINARY=/usr/bin/pg_restore

# Health & Alerts
BACKUP_MAX_AGE_HOURS=26
BACKUP_NOTIFICATIONS_ENABLED=true
BACKUP_NOTIFICATION_EMAIL=devops@example.com
BACKUP_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/your-webhook-id/your-token
```
