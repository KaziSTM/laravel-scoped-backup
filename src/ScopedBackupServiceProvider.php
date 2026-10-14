<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\PruneBackupsAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\VerifyBackupHealthAction;
use KaziSTM\LaravelScopedBackup\Commands\BackupFullCommand;
use KaziSTM\LaravelScopedBackup\Commands\BackupMonitorCommand;
use KaziSTM\LaravelScopedBackup\Commands\BackupPruneCommand;
use KaziSTM\LaravelScopedBackup\Commands\BackupRestoreCommand;
use KaziSTM\LaravelScopedBackup\Commands\BackupScopedCommand;
use KaziSTM\LaravelScopedBackup\Database\DatabaseBackupDriverManager;
use KaziSTM\LaravelScopedBackup\Dumpers\DatabaseDumperManager;
use KaziSTM\LaravelScopedBackup\Dumpers\PostgresDumper;
use KaziSTM\LaravelScopedBackup\Events\BackupFailed;
use KaziSTM\LaravelScopedBackup\Events\BackupSuccessful;
use KaziSTM\LaravelScopedBackup\Events\RestoreFailed;
use KaziSTM\LaravelScopedBackup\Events\UnhealthyBackupDetected;
use KaziSTM\LaravelScopedBackup\Notifications\BackupNotificationDispatcher;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Services\BackupLockManager;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;
use KaziSTM\LaravelScopedBackup\Services\HealthMonitor;
use KaziSTM\LaravelScopedBackup\Services\ScopedExporter;
use KaziSTM\LaravelScopedBackup\Services\ScopedImporter;

class ScopedBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/scoped-backup.php',
            'scoped-backup'
        );

        $this->app->singleton(DatabaseBackupDriverManager::class);
        $this->app->alias(
            DatabaseBackupDriverManager::class,
            DatabaseDumperManager::class
        );
        $this->app->singleton(PostgresDumper::class);
        $this->app->singleton(ArchiveManager::class);
        $this->app->singleton(ChecksumValidator::class);
        $this->app->singleton(BackupLockManager::class);
        $this->app->singleton(ScopedExporter::class);
        $this->app->singleton(ScopedImporter::class);
        $this->app->singleton(HealthMonitor::class);

        $this->app->singleton(BackupNotificationDispatcher::class);

        $this->app->singleton(CreateFullBackupAction::class);
        $this->app->singleton(CreateScopedBackupAction::class);
        $this->app->singleton(RestoreFullBackupAction::class);
        $this->app->singleton(RestoreScopedBackupAction::class);
        $this->app->singleton(PruneBackupsAction::class);
        $this->app->singleton(VerifyBackupHealthAction::class);
    }

    public function boot(): void
    {
        $dispatcher = $this->app->make(BackupNotificationDispatcher::class);

        Event::listen(
            BackupFailed::class,
            [$dispatcher, 'handleBackupFailed']
        );
        Event::listen(
            BackupSuccessful::class,
            [$dispatcher, 'handleBackupSuccessful']
        );
        Event::listen(
            UnhealthyBackupDetected::class,
            [$dispatcher, 'handleUnhealthyDetected']
        );
        Event::listen(
            RestoreFailed::class,
            [$dispatcher, 'handleRestoreFailed']
        );

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/scoped-backup.php' => config_path('scoped-backup.php'),
            ], 'scoped-backup-config');

            if (! class_exists('CreateBackupRecordsTable')) {
                $this->publishes([
                    __DIR__.'/../database/migrations/create_backup_records_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_backup_records_table.php'),
                ], 'scoped-backup-migrations');
            }

            $this->commands([
                BackupFullCommand::class,
                BackupScopedCommand::class,
                BackupRestoreCommand::class,
                BackupPruneCommand::class,
                BackupMonitorCommand::class,
            ]);
        }
    }
}
