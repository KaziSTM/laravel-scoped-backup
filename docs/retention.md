# Retention & Pruning Policies

Automated retention prevents storage exhaustion while preserving critical audit records and preventing catastrophic data loss.

---

## Retention Policy Rules

The package enforces three core retention guarantees:

1. **Successful Backups Limit:**
   - Retains the most recent `N` successful, unpruned backups (default: `7`, configured in `config/scoped-backup.php` via `retention.limit`).
   - Evaluated chronologically by `completed_at` descending.
2. **Last-Known-Good Failsafe (`protect_last_successful`):**
   - If only one successful backup remains in history, it is **never pruned**, even if the retention limit is configured to `0` or `1`. This guarantees you never prune your only viable recovery point.
3. **Audit History Preservation (`preserve_audit_records`):**
   - When backups expire, only the physical file is deleted from storage.
   - The tracking row in `database_backups` is updated with `pruned_at = now()`.
   - Durations, timestamps, sizes, checksums, and execution triggers remain permanently queryable for compliance and capacity planning.

---

## How Failed Backups Affect Retention

- **Never Counted Toward Quota:** Failed backups do not count toward your retention limit (`successful()` query scope). A burst of failed backup attempts will never cause previous successful backups to be prematurely pruned.
- **Audit Preservation:** Failed records remain in the tracking table with their error messages and execution times for debugging.

---

## Separate Policies by Backup Type

Full database backups and scoped dataset backups can be pruned independently:

```bash
# Prune only full backups, keeping the 14 most recent
php artisan scoped-backup:prune --retention=14 --type=full

# Prune only scoped backups, keeping the 30 most recent
php artisan scoped-backup:prune --retention=30 --type=scoped
```

---

## Path Traversal Safeguards

Before deleting any physical file from disk during a pruning run, `PruneBackupsAction` validates the recorded path:

- Rejects any path containing `..` (directory traversal).
- Rejects absolute filesystem root paths (e.g. `/etc/` or `C:\`).
- If an unsafe path is detected, the physical deletion is aborted, a warning is logged, and the database record is safely marked as pruned.

---

## Programmatic Pruning API

To trigger retention pruning in application code:

```php
use KaziSTM\LaravelScopedBackup\Actions\PruneBackupsAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;

$pruner = app(PruneBackupsAction::class);

// Prune full backups, retaining the 7 most recent
$prunedCount = $pruner->execute(
    retentionLimit: 7,
    type: BackupType::FULL,
);

Log::info("Pruned {$prunedCount} expired backups.");
```
