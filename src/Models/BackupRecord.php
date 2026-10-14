<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;

/**
 * @property int $id
 * @property BackupStatus $status
 * @property BackupTrigger $trigger
 * @property string $driver
 * @property string $database_name
 * @property string|null $host
 * @property string $disk
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property string|null $checksum_sha256
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property float|null $duration_seconds
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $pruned_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BackupRecord extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return (string) config('scoped-backup.table_name', 'database_backups');
    }

    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'trigger' => BackupTrigger::class,
            'file_size_bytes' => 'integer',
            'duration_seconds' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'pruned_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isSuccessful(): bool
    {
        return $this->status === BackupStatus::SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->status === BackupStatus::FAILED;
    }

    public function isRunning(): bool
    {
        return $this->status === BackupStatus::RUNNING;
    }

    public function isPruned(): bool
    {
        return $this->pruned_at !== null;
    }

    public function getBackupType(): BackupType
    {
        $typeString = $this->metadata['backup_type'] ?? null;
        if (is_string($typeString) && $typeString !== '') {
            return BackupType::tryFrom($typeString) ?? BackupType::FULL;
        }

        return BackupType::FULL;
    }

    /**
     * @param  Builder<BackupRecord>  $query
     * @return Builder<BackupRecord>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::SUCCEEDED);
    }

    /**
     * @param  Builder<BackupRecord>  $query
     * @return Builder<BackupRecord>
     */
    public function scopeUnpruned(Builder $query): Builder
    {
        return $query->whereNull('pruned_at');
    }

    /**
     * @param  Builder<BackupRecord>  $query
     * @return Builder<BackupRecord>
     */
    public function scopeLatestCompleted(Builder $query): Builder
    {
        return $query->orderByDesc('completed_at')->orderByDesc('id');
    }
}
