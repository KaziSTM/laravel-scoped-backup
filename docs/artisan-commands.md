# Artisan Commands Reference

The package registers five Artisan commands under the `scoped-backup:` namespace. Every signature and option below has been verified against the implementation.

---

## 1. `scoped-backup:full`

Create a verified full database and optional filesystem ZIP backup with retention tracking.

### Signature & Options

```text
php artisan scoped-backup:full
    {--trigger=manual : The backup trigger type (manual or scheduled)}
    {--connection= : The database connection to backup}
    {--disk= : Filesystem storage disk}
    {--path= : Relative path within the storage disk}
    {--retention= : Number of successful backups to retain}
    {--include-files : Include configured filesystem paths}
```

### Examples

```bash
# Basic manual full backup using config defaults
php artisan scoped-backup:full

# Run scheduled backup to S3 including files, retaining 14 backups
php artisan scoped-backup:full --trigger=scheduled --disk=s3 --include-files --retention=14 --connection=pgsql
```

---

## 2. `scoped-backup:scoped`

Create a portable scoped JSON Lines model or dataset snapshot.

### Signature & Options

```text
php artisan scoped-backup:scoped
    {model : Fully qualified Eloquent model class to snapshot}
    {--name= : Name identifier for the scoped dataset (defaults to class basename)}
    {--relations=* : Explicit relationships to include (can be specified multiple times)}
    {--identity=* : Natural identity key(s) for record matching (can be specified multiple times)}
    {--chunk=100 : Chunk size for streaming export}
    {--disk= : Filesystem storage disk}
    {--path= : Relative path within the storage disk}
    {--retention= : Retention limit for scoped backups}
```

### Examples

```bash
# Snapshot all Users with email as identity key
php artisan scoped-backup:scoped "App\Models\User" --name=users_snapshot --identity=email

# Snapshot Orders with relationships and chunk size 50
php artisan scoped-backup:scoped "App\Models\Order" \
    --name=orders_q4 \
    --relations=items \
    --relations=customer \
    --identity=order_number \
    --chunk=50 \
    --disk=s3
```

---

## 3. `scoped-backup:restore`

Safely restore a full database backup or scoped dataset archive.

### Signature & Options

```text
php artisan scoped-backup:restore
    {target : Backup ID, archive file path, or database dump path}
    {--type=scoped : Type of backup to restore (scoped or full)}
    {--connection= : Target database connection for restore}
    {--dry-run : Simulate restoration without persisting modifications (scoped only)}
    {--verify-only : Inspect and verify archive without restoring (full only)}
    {--restore-files : Restore included filesystem assets (full only)}
    {--force : Force destructive restoration in production}
```

### Examples

```bash
# Dry-run preview of a scoped dataset restore
php artisan scoped-backup:restore storage/app/backups/scoped_users.zip --dry-run

# Live restore of a scoped backup
php artisan scoped-backup:restore storage/app/backups/scoped_users.zip

# Pre-flight verify a full backup without restoring
php artisan scoped-backup:restore 1 --type=full --verify-only

# Restore full backup into a dedicated recovery connection
php artisan scoped-backup:restore 1 --type=full --connection=pgsql_recovery

# Force destructive restore of full backup in production
php artisan scoped-backup:restore 1 --type=full --force
```

---

## 4. `scoped-backup:prune`

Prune expired backup archives according to the retention policy.

### Signature & Options

```text
php artisan scoped-backup:prune
    {--retention= : Override retention count limit}
    {--type= : Filter by backup type (full or scoped)}
```

### Examples

```bash
# Prune using configured retention limit (default: 7)
php artisan scoped-backup:prune

# Override retention limit to 14
php artisan scoped-backup:prune --retention=14

# Prune only scoped backups, keeping 5
php artisan scoped-backup:prune --retention=5 --type=scoped
```

---

## 5. `scoped-backup:monitor`

Evaluate backup freshness, archive integrity, size anomaly, and recent failure health.

### Signature

```text
php artisan scoped-backup:monitor
```

### Exit Codes

- `0`: Success (all checks passed or warnings only).
- `1`: Failure (one or more critical checks failed: stale backup, missing archive, or checksum mismatch).
