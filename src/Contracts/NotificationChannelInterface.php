<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Contracts;

interface NotificationChannelInterface
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $event, string $title, string $message, array $context = []): void;
}
