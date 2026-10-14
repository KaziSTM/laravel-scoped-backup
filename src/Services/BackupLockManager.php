<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;

class BackupLockManager
{
    public const EXECUTION_LOCK_KEY = 'scoped_backup:execution_lock';

    public const PRUNE_LOCK_KEY = 'scoped_backup:prune_lock';

    public function acquireExecutionLock(int $ttlSeconds = 600): Lock
    {
        $lock = Cache::lock(self::EXECUTION_LOCK_KEY, $ttlSeconds);

        if (! $lock->get()) {
            throw new BackupException('Another backup process is currently in progress.');
        }

        return $lock;
    }

    public function acquirePruneLock(int $ttlSeconds = 60): Lock
    {
        $lock = Cache::lock(self::PRUNE_LOCK_KEY, $ttlSeconds);

        return $lock;
    }

    public function acquireScopedLock(string $scopeKey, int $ttlSeconds = 600): Lock
    {
        $key = "scoped_backup:lock:{$scopeKey}";
        $lock = Cache::lock($key, $ttlSeconds);

        if (! $lock->get()) {
            throw new BackupException("Another backup process for scope [{$scopeKey}] is currently in progress.");
        }

        return $lock;
    }
}
