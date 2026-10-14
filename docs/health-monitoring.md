# Health Monitoring & Anomaly Detection

The health monitoring subsystem (`HealthMonitor` and `VerifyBackupHealthAction`) verifies that backups are running on schedule, artifacts are intact on storage, and file sizes reflect expected database growth.

---

## Automated Health Checks

Executing `php artisan scoped-backup:monitor` runs four distinct health checks:

### 1. Freshness & TTL Check (`backup_freshness`)
- Evaluates the timestamp of the latest successful backup in `database_backups`.
- Compares its age against `health.max_age_hours` (default: `28` hours).
- **Result:** Fails if no successful backup exists or if the latest backup is older than the configured threshold (e.g. daily cron failed silently).

### 2. Archive Cryptographic Integrity (`archive_integrity`)
- Verifies that the physical `.zip` file exists on the designated storage disk.
- Calculates the streaming cryptographic SHA-256 checksum of the file and verifies it against the `checksum_sha256` recorded in the database and `manifest.json`.
- Compatible with both local filesystems and remote cloud storage (S3, GCS) via streaming hashes.
- **Result:** Fails if the file is missing or if bit rot, truncation, or unauthorized alteration has occurred.

### 3. Historical Size Anomaly Detection (`size_anomaly`)
- Compares the archive size against the average size of the previous 5 successful backups.
- If the archive size drops by more than `health.size_deviation_threshold_percent` (default: `50%`), a warning is raised.
- **Why it matters:** A sudden 80% decrease in backup size often indicates that a major table was truncated or a critical database permission was revoked, causing `pg_dump` or `mysqldump` to silently skip tables.

### 4. Recent Failure Rate (`recent_runs`)
- Evaluates the outcome of the most recent 5 backup attempts.
- **Result:** Raises a warning if the most recent run failed, displaying the error message for operational triage.

---

## Command Output & Exit Codes

```bash
php artisan scoped-backup:monitor
```

### Exit Codes for Monitoring Integration

| Exit Code | Meaning | External Monitoring Action |
| :--- | :--- | :--- |
| `0` | All checks passed or non-critical warnings only | Alerting clear (Green) |
| `1` | One or more critical checks failed (stale or corrupted backup) | Trigger PagerDuty / OpsGenie incident (Red) |

### Cron Integration Example

Run the monitor command every hour and route failures to your operations alert channel:

```bash
0 * * * * cd /var/www/app && php artisan scoped-backup:monitor >> /var/log/backup-health.log 2>&1
```

---

## Programmatic Health Check API

```php
use KaziSTM\LaravelScopedBackup\Actions\VerifyBackupHealthAction;

$verifier = app(VerifyBackupHealthAction::class);
$result = $verifier->check();

if (! $result->isHealthy) {
    foreach ($result->failures() as $failure) {
        Log::critical("Backup failure in [{$failure['check']}]: {$failure['message']}");
    }
}
```

---

## Operational Scope & Honest Limitations

> [!NOTE]
> **What Monitoring Detects:**
> - Stale backups caused by hung cron workers or scheduler timeouts.
> - Storage network drops or accidental file deletions.
> - Data transfer bit rot and storage corruption.
> - Massive table data loss via size drop heuristics.

> [!WARNING]
> **What Monitoring Does Not Detect:**
> - Checksum verification confirms that bytes on disk match the bytes written at backup time; it does not prove that those bytes represent a logically consistent database state.
> - Monitoring does not repair damaged backups.
> - Regular restore testing (see [Restoration](restoration.md)) is required for complete disaster recovery validation.
