<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Notifications;

use Illuminate\Support\Facades\Log;
use KaziSTM\LaravelScopedBackup\Contracts\NotificationChannelInterface;
use KaziSTM\LaravelScopedBackup\Events\BackupFailed;
use KaziSTM\LaravelScopedBackup\Events\BackupSuccessful;
use KaziSTM\LaravelScopedBackup\Events\RestoreFailed;
use KaziSTM\LaravelScopedBackup\Events\UnhealthyBackupDetected;
use KaziSTM\LaravelScopedBackup\Notifications\Channels\MailChannel;
use KaziSTM\LaravelScopedBackup\Notifications\Channels\WebhookChannel;
use Throwable;

class BackupNotificationDispatcher
{
    /**
     * @var array<string, NotificationChannelInterface>
     */
    protected array $channels = [];

    public function __construct()
    {
        $this->channels['mail'] = app(MailChannel::class);
        $this->channels['webhook'] = app(WebhookChannel::class);
    }

    public function registerChannel(string $name, NotificationChannelInterface $channel): void
    {
        $this->channels[$name] = $channel;
    }

    public function handleBackupFailed(BackupFailed $event): void
    {
        if (! $this->isEventEnabled('backup_failed')) {
            return;
        }

        $title = "Backup Failed: {$event->record->database_name}";
        $message = "Backup [ID: {$event->record->id}] failed: {$event->exception->getMessage()}";
        $context = [
            'backup_id' => $event->record->id,
            'database' => $event->record->database_name,
            'driver' => $event->record->driver,
            'trigger' => $event->record->trigger->value,
            'duration' => "{$event->record->duration_seconds}s",
            'error' => $event->exception->getMessage(),
        ];

        $this->broadcast('backup_failed', $title, $message, $context);
    }

    public function handleBackupSuccessful(BackupSuccessful $event): void
    {
        if (! $this->isEventEnabled('backup_succeeded')) {
            return;
        }

        $title = "Backup Succeeded: {$event->record->database_name}";
        $message = "Backup [ID: {$event->record->id}] completed and verified successfully ({$event->record->file_size_bytes} bytes).";
        $context = [
            'backup_id' => $event->record->id,
            'database' => $event->record->database_name,
            'disk' => $event->record->disk,
            'path' => $event->record->file_path,
            'duration' => "{$event->record->duration_seconds}s",
        ];

        $this->broadcast('backup_succeeded', $title, $message, $context);
    }

    public function handleUnhealthyDetected(UnhealthyBackupDetected $event): void
    {
        if (! $this->isEventEnabled('unhealthy_detected')) {
            return;
        }

        $title = 'Backup Health Alert';
        $failures = $event->result->failures();
        $message = 'Backup health check detected '.count($failures).' critical failure(s).';
        $context = [
            'failures' => $failures,
            'warnings' => $event->result->warnings(),
        ];

        $this->broadcast('unhealthy_detected', $title, $message, $context);
    }

    public function handleRestoreFailed(RestoreFailed $event): void
    {
        if (! $this->isEventEnabled('restore_failed') && ! $this->isEventEnabled('backup_failed')) {
            return;
        }

        $title = 'Restore Operation Failed';
        $message = "Restoration of {$event->type->value} backup [{$event->archivePath}] failed: {$event->exception->getMessage()}";
        $context = [
            'type' => $event->type->value,
            'archive' => $event->archivePath,
            'error' => $event->exception->getMessage(),
        ];

        $this->broadcast('restore_failed', $title, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function broadcast(string $eventName, string $title, string $message, array $context): void
    {
        if (! (bool) config('scoped-backup.notifications.enabled', false)) {
            return;
        }

        $configuredChannels = (array) config('scoped-backup.notifications.channels', []);

        foreach ($configuredChannels as $channelName) {
            if (isset($this->channels[$channelName])) {
                try {
                    $this->channels[$channelName]->send($eventName, $title, $message, $context);
                } catch (Throwable $e) {
                    Log::warning("Notification dispatch failed for channel [{$channelName}]: {$e->getMessage()}");
                }
            }
        }
    }

    protected function isEventEnabled(string $eventName): bool
    {
        return (bool) config("scoped-backup.notifications.events.{$eventName}", false);
    }
}
