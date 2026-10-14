# Notifications & Alerting

The notification subsystem notifies operations and DevOps teams when backups fail, health checks degrade, or restores encounter errors.

---

## Architecture & Safety Guarantees

Notifications are coordinated by `BackupNotificationDispatcher`, which listens to Laravel events dispatched by backup actions:

1. **Isolation Guarantee:** Notification dispatch errors (e.g. webhook timeout, mail server downtime) are caught and logged as warnings. A notification failure will **never** cause an active backup to fail, crash, or overwrite its error tracking record.
2. **Rate & Timeout Protection:** HTTP webhooks enforce a strict 5-second timeout to prevent background workers from hanging.
3. **Payload Truncation:** Webhook payloads respect Discord and JSON payload limits (description truncated to 2048 chars, context fields to 1024 chars, maximum 25 embed fields).

---

## Configuration

In `config/scoped-backup.php`:

```php
'notifications' => [
    'enabled' => env('BACKUP_NOTIFICATIONS_ENABLED', true),
    'channels' => ['mail', 'webhook'],
    'mail' => [
        'to' => env('BACKUP_NOTIFICATION_EMAIL', 'devops@example.com'),
    ],
    'webhook' => [
        'url' => env('BACKUP_DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/...'),
        'format' => 'discord', // 'discord' or 'generic_json'
    ],
    'events' => [
        'backup_failed' => true,
        'unhealthy_detected' => true,
        'restore_failed' => true,
        'backup_succeeded' => false, // Set to true if you want confirmations for every run
    ],
],
```

---

## Built-In Channels

### 1. Webhook Channel (`WebhookChannel`)
Supports two payload formats:
- **Discord (`format: 'discord'`):** Generates rich Discord embed messages with color coding (Red for failures and health alerts; Green for successful runs) and key-value fields.
- **Generic JSON (`format: 'generic_json'`):** Dispatches standard JSON POST payloads compatible with Slack webhook proxies, PagerDuty generic webhooks, Zapier, or internal microservices:

```json
{
    "event": "backup_failed",
    "title": "Backup Failed: company_radar",
    "message": "Backup [ID: 42] failed: pg_dump process timed out",
    "context": {
        "backup_id": 42,
        "database": "company_radar",
        "driver": "pgsql",
        "duration": "600.12s"
    },
    "timestamp": "2026-10-14T14:00:00+01:00"
}
```

### 2. Mail Channel (`MailChannel`)
Sends operational emails using Laravel's configured mail transport (`config('mail.default')`). Messages include event summaries and JSON-formatted context blocks.

---

## Registering Custom Channels

To add a custom notification channel (e.g. Slack, Telegram, SMS), implement `NotificationChannelInterface`:

```php
namespace App\Notifications\Channels;

use KaziSTM\LaravelScopedBackup\Contracts\NotificationChannelInterface;

class SlackWebhookChannel implements NotificationChannelInterface
{
    public function send(string $event, string $title, string $message, array $context = []): void
    {
        // Custom Slack API implementation...
    }
}
```

Register your channel with `BackupNotificationDispatcher` in your `AppServiceProvider::boot()`:

```php
use KaziSTM\LaravelScopedBackup\Notifications\BackupNotificationDispatcher;
use App\Notifications\Channels\SlackWebhookChannel;

public function boot(): void
{
    $dispatcher = app(BackupNotificationDispatcher::class);
    $dispatcher->registerChannel('slack', app(SlackWebhookChannel::class));
}
```
