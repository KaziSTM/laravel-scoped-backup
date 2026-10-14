<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Services;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;

class ChecksumValidator
{
    public function sha256(string $filePath): string
    {
        if (! File::exists($filePath)) {
            throw new BackupVerificationException("File at [{$filePath}] does not exist for checksum calculation.");
        }

        $hash = hash_file('sha256', $filePath);

        if ($hash === false) {
            throw new BackupVerificationException("Failed to compute SHA-256 checksum for [{$filePath}].");
        }

        return $hash;
    }

    public function verify(string $filePath, string $expectedHash): bool
    {
        return hash_equals(strtolower($expectedHash), strtolower($this->sha256($filePath)));
    }

    /**
     * Compute SHA-256 checksum from an open readable stream.
     *
     * @param  resource  $stream
     */
    public function sha256Stream($stream): string
    {
        if (! is_resource($stream)) {
            throw new BackupVerificationException('Invalid resource provided for stream checksum calculation.');
        }

        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $stream);

        return hash_final($ctx);
    }
}
