# Scheduling & Automation

Automating backups and health checks ensures continuous disaster recovery readiness. In Laravel 11+, scheduled tasks are defined in `routes/console.php`.

---

## Recommended Schedule Configuration

Add the following schedule definitions to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// 1. Daily Full Database & Filesystem Backup at 01:00 AM
Schedule::command('scoped-backup:full --trigger=scheduled --include-files')
    ->dailyAt(config('scoped-backup.schedule.time', '01:00'))
    ->withoutOverlapping(60) // Prevent overlapping runs with 60-minute lock TTL
    ->onOneServer()          // Ensure only one server in multi-server clusters executes the backup
    ->runInBackground();

// 2. Hourly Health Check & Integrity Monitoring
Schedule::command('scoped-backup:monitor')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// 3. Weekly Scoped Reference Dataset Export (Optional)
Schedule::command('scoped-backup:scoped "App\Models\Tenant" --name=tenants_weekly --identity=slug')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();
```

---

## Concurrency Protection & Overlapping Runs

The package implements built-in atomic cache locks via `BackupLockManager`:

- **Execution Lock (`scoped_backup:execution_lock`):** Prevents concurrent full backup executions from competing for database CPU, memory, and disk bandwidth.
- **Scoped Locks (`scoped_backup:lock:<scope_name>`):** Allows independent scoped exports to run concurrently while preventing overlapping exports for the same named scope.
- **Pruning Lock (`scoped_backup:prune_lock`):** Ensures that prune operations do not collide when triggered from multiple schedulers.

When configuring `Schedule::command()`, combining Laravel's `withoutOverlapping()` with the package's internal `BackupLockManager` guarantees total process isolation.

---

## Off-Peak Timing & Performance Recommendations

1. **Schedule During Low Activity Windows:**
   Native database dumps (e.g. `pg_dump` or `mysqldump`) consume disk I/O and database CPU. Schedule full backups during your application's quietest window (typically 01:00 - 04:00 local time).
2. **Monitor Duration Metrics:**
   Regularly inspect the `duration_seconds` column in `database_backups` to observe how backup time scales with data growth.
3. **Multi-Server Clusters (`onOneServer`):**
   If running behind a load balancer with multiple web/worker instances, always chain `->onOneServer()` so that only a single instance runs the backup.
