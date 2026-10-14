<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Notifications\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use KaziSTM\LaravelScopedBackup\Contracts\NotificationChannelInterface;
use Throwable;

class MailChannel implements NotificationChannelInterface
{
    public function send(string $event, string $title, string $message, array $context = []): void
    {
        $to = config('scoped-backup.notifications.mail.to');
        if (empty($to)) {
            return;
        }

        try {
            $body = "{$title}\n\n{$message}\n\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            Mail::raw($body, function ($msg) use ($to, $title) {
                $msg->to($to)->subject("[Backup Alert] {$title}");
            });
        } catch (Throwable $e) {
            // Notification delivery failure must never corrupt or erase primary backup error
            Log::warning("Failed to send backup mail notification: {$e->getMessage()}", [
                'event' => $event,
                'recipient' => $to,
            ]);
        }
    }
}
