<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Notifications\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use KaziSTM\LaravelScopedBackup\Contracts\NotificationChannelInterface;
use Throwable;

class WebhookChannel implements NotificationChannelInterface
{
    public function send(string $event, string $title, string $message, array $context = []): void
    {
        $url = config('scoped-backup.notifications.webhook.url');
        if (empty($url)) {
            return;
        }

        $format = (string) config('scoped-backup.notifications.webhook.format', 'discord');

        try {
            $payload = $format === 'discord'
                ? $this->buildDiscordPayload($event, $title, $message, $context)
                : $this->buildGenericPayload($event, $title, $message, $context);

            $response = Http::timeout(5)->post((string) $url, $payload);

            if (! $response->successful()) {
                Log::warning("Backup webhook returned non-success HTTP status {$response->status()}", [
                    'event' => $event,
                    'url' => $url,
                ]);
            }
        } catch (Throwable $e) {
            // Notification delivery failure must never corrupt or erase primary backup error
            Log::warning("Failed to dispatch backup webhook notification: {$e->getMessage()}", [
                'event' => $event,
                'url' => $url,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildDiscordPayload(string $event, string $title, string $message, array $context): array
    {
        $isError = str_contains($event, 'failed') || str_contains($event, 'unhealthy');
        $color = $isError ? 0xFF0000 : 0x00FF00; // Red for failure, Green for success

        $fields = [];
        foreach ($context as $key => $val) {
            $strVal = is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_SLASHES);
            $fields[] = [
                'name' => (string) $key,
                'value' => mb_substr((string) $strVal, 0, 1024),
                'inline' => true,
            ];
        }

        return [
            'embeds' => [
                [
                    'title' => $title,
                    'description' => mb_substr($message, 0, 2048),
                    'color' => $color,
                    'fields' => array_slice($fields, 0, 25),
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildGenericPayload(string $event, string $title, string $message, array $context): array
    {
        return [
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
