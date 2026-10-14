# Programmatic API Reference

All backup, restore, pruning, and monitoring operations are exposed as invokable service actions designed for dependency injection.

---

## Service Actions

### 1. `CreateFullBackupAction`

Creates a verified full database and filesystem archive.

```php
namespace KaziSTM\LaravelScopedBackup\Actions;

use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

public function execute(
    BackupTrigger $trigger = BackupTrigger::MANUAL,
    ?string $connection = null,
    ?string $disk = null,
    ?string $path = null,
    ?int $retentionLimit = null,
    ?bool $includeFiles = null,
    array $options = [],
): BackupRecord;
```

#### Usage Example

```php
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;

$action = app(CreateFullBackupAction::class);

$record = $action->execute(
    trigger: BackupTrigger::SCHEDULED,
    connection: 'pgsql',
    disk: 's3',
    path: 'daily-backups',
    retentionLimit: 14,
    includeFiles: true,
);

echo "Backup complete: ID {$record->id}, Size: {$record->file_size_bytes} bytes";
```

---

### 2. `CreateScopedBackupAction`

Creates a scoped JSON Lines dataset snapshot.

```php
namespace KaziSTM\LaravelScopedBackup\Actions;

use KaziSTM\LaravelScopedBackup\Contracts\ScopedBackupDefinitionInterface;
use KaziSTM\LaravelScopedBackup\DTO\BackupStats;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

public function execute(
    ScopedBackupDefinitionInterface $definition,
    BackupTrigger $trigger = BackupTrigger::MANUAL,
    ?string $disk = null,
    ?string $path = null,
    ?int $retentionLimit = null,
    array $options = [],
): array; // returns ['record' => BackupRecord, 'stats' => BackupStats]
```

#### Usage Example

```php
use App\Models\Tenant;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;

$def = ScopedBackupDefinition::make('tenant_acme')
    ->model(Tenant::class, fn ($q) => $q->where('slug', 'acme'))
    ->include(['users', 'settings'])
    ->identity('slug');

$result = app(CreateScopedBackupAction::class)->execute($def);

$record = $result['record']; // BackupRecord
$stats = $result['stats'];   // BackupStats
```

---

### 3. `RestoreFullBackupAction`

Restores a full database archive into a target connection.

```php
namespace KaziSTM\LaravelScopedBackup\Actions;

use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

public function execute(
    int|string|BackupRecord $backup,
    ?string $targetConnection = null,
    bool $force = false,
    bool $verifyOnly = false,
    bool $restoreFiles = false,
): array;
```

#### Usage Example

```php
use KaziSTM\LaravelScopedBackup\Actions\RestoreFullBackupAction;

$action = app(RestoreFullBackupAction::class);

$result = $action->execute(
    backup: 42,
    targetConnection: 'pgsql_recovery',
    force: false,
    verifyOnly: false,
    restoreFiles: false,
);
```

---

### 4. `RestoreScopedBackupAction`

Restores a scoped dataset archive into the database using upsert logic.

```php
namespace KaziSTM\LaravelScopedBackup\Actions;

use KaziSTM\LaravelScopedBackup\DTO\RestoreStats;

public function execute(
    string $archivePath,
    array $options = [],
): RestoreStats;
```

#### Supported Options in `$options`
- `'connection'`: Target database connection name.
- `'dry_run'`: Boolean (when true, simulates without saving).
- `'mode'`: `RestoreMode::DRY_RUN` or `RestoreMode::TRANSACTIONAL_UPSERT`.
- `'model'`: Override the target root Eloquent model class.
- `'identity_keys'`: Override natural key array for matching.

#### Usage Example

```php
use KaziSTM\LaravelScopedBackup\Actions\RestoreScopedBackupAction;

$action = app(RestoreScopedBackupAction::class);

$stats = $action->execute('/path/to/scoped_backup.zip', [
    'dry_run' => false,
]);

echo "Created: {$stats->recordsCreated}, Updated: {$stats->recordsUpdated}";
```

---

### 5. `PruneBackupsAction`

Enforces retention policies by pruning expired backup archives.

```php
namespace KaziSTM\LaravelScopedBackup\Actions;

use KaziSTM\LaravelScopedBackup\Enums\BackupType;

public function execute(
    ?int $retentionLimit = null,
    ?BackupType $type = null,
): int; // returns number of pruned backups
```

---

### 6. `VerifyBackupHealthAction`

Evaluates system freshness, archive integrity, and historical size anomalies.

```php
namespace KaziSTM\LaravelScopedBackup\Actions;

use KaziSTM\LaravelScopedBackup\DTO\HealthCheckResult;

public function check(): HealthCheckResult;
```

---

## Core DTOs & Models

### `BackupRecord` (Model)
- Eloquent model representing the tracking table (`database_backups`).
- Methods: `isSuccessful()`, `isFailed()`, `isRunning()`, `isPruned()`, `getBackupType()`.
- Scopes: `scopeSuccessful()`, `scopeUnpruned()`, `scopeLatestCompleted()`.

### `BackupStats` (DTO)
- Properties:
  - `int $recordsExported`: Total root records exported.
  - `array<string, int> $entityCounts`: Counts per entity/model.
  - `float $durationSeconds`: Execution duration in seconds.
  - `int $fileSizeBytes`: Output ZIP file size.
  - `?string $checksumSha256`: Cryptographic SHA-256 hash.

### `RestoreStats` (DTO)
- Properties:
  - `int $recordsCreated`: New records inserted.
  - `int $recordsUpdated`: Existing records modified.
  - `int $recordsUnchanged`: Existing records with identical attributes.
  - `int $recordsSkipped`: Malformed records skipped.
  - `bool $isDryRun`: Whether dry run was active.
  - `float $durationSeconds`: Execution duration.
  - `array<int, string> $errors`: Error messages.
  - `array<int, string> $warnings`: Warning messages.

### `HealthCheckResult` (DTO)
- Properties:
  - `bool $isHealthy`: True if all checks passed.
  - `array<int, array{check: string, status: string, message: string, context?: array}> $checks`: Individual check outcomes.
- Methods: `failures()`, `warnings()`, `hasWarnings()`.

---

## Lifecycle Events

| Event Class | Trigger Point | Payload Data |
| :--- | :--- | :--- |
| `BackupStarting` | Backup execution starts | `BackupType $type, BackupTrigger $trigger, BackupRecord $record` |
| `BackupSuccessful` | Backup verified, uploaded, and logged | `BackupRecord $record` |
| `BackupFailed` | Dump, packaging, or upload failed | `BackupRecord $record, Throwable $exception` |
| `RestoreStarting` | Restore operation initiated | `BackupType $type, string $archivePath, array $options` |
| `RestoreSuccessful` | Restore completed and verified | `BackupType $type, string $archivePath, array $metadata` |
| `RestoreFailed` | Restore failed | `BackupType $type, string $archivePath, Throwable $exception` |
| `UnhealthyBackupDetected` | Health monitor detects failure or warning | `HealthCheckResult $result` |
