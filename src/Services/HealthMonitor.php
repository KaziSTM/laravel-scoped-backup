<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Services;

use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\DTO\HealthCheckResult;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

class HealthMonitor
{
    public function __construct(
        protected readonly ChecksumValidator $checksumValidator,
    ) {}

    public function check(): HealthCheckResult
    {
        $checks = [];
        $isHealthy = true;

        $modelClass = config('scoped-backup.model', BackupRecord::class);

        // 1. Check: Latest Successful Backup & TTL Freshness
        /** @var BackupRecord|null $latestSuccessful */
        $latestSuccessful = $modelClass::successful()
            ->latestCompleted()
            ->first();

        if ($latestSuccessful === null) {
            $checks[] = [
                'check' => 'backup_freshness',
                'status' => 'failed',
                'message' => 'No successful backups found in backup tracking history.',
            ];
            $isHealthy = false;
        } else {
            $maxAgeHours = (int) config('scoped-backup.health.max_age_hours', 28);
            $completedAt = $latestSuccessful->completed_at ?? $latestSuccessful->created_at;
            $ageHours = round(abs(now()->diffInMinutes($completedAt)) / 60, 1);

            if ($ageHours > $maxAgeHours) {
                $checks[] = [
                    'check' => 'backup_freshness',
                    'status' => 'failed',
                    'message' => "Latest backup is stale ({$ageHours} hours old; configured TTL threshold is {$maxAgeHours} hours).",
                    'context' => [
                        'backup_id' => $latestSuccessful->id,
                        'age_hours' => $ageHours,
                        'max_age_hours' => $maxAgeHours,
                    ],
                ];
                $isHealthy = false;
            } else {
                $checks[] = [
                    'check' => 'backup_freshness',
                    'status' => 'passed',
                    'message' => "Latest backup is fresh ({$ageHours} hours old).",
                    'context' => [
                        'backup_id' => $latestSuccessful->id,
                        'age_hours' => $ageHours,
                    ],
                ];
            }

            // 2. Check: Physical File Existence & Checksum Validation
            $diskName = $latestSuccessful->disk ?: (string) config('scoped-backup.storage.disk', 'local');
            $disk = Storage::disk($diskName);
            $filePath = $latestSuccessful->file_path;

            if ($filePath === null || ! $disk->exists($filePath)) {
                $checks[] = [
                    'check' => 'archive_integrity',
                    'status' => 'failed',
                    'message' => "Physical backup archive file is missing from storage disk [{$diskName}] at [{$filePath}].",
                    'context' => [
                        'backup_id' => $latestSuccessful->id,
                        'disk' => $diskName,
                        'path' => $filePath,
                    ],
                ];
                $isHealthy = false;
            } else {
                $computedSha = null;
                try {
                    $fullPath = $disk->path($filePath);
                    $computedSha = $this->checksumValidator->sha256($fullPath);
                } catch (\Throwable) {
                    $stream = $disk->readStream($filePath);
                    if ($stream !== false && is_resource($stream)) {
                        $computedSha = $this->checksumValidator->sha256Stream($stream);
                        fclose($stream);
                    }
                }

                $expectedSha = $latestSuccessful->checksum_sha256;

                if ($expectedSha !== null && ! hash_equals(strtolower($expectedSha), strtolower($computedSha))) {
                    $checks[] = [
                        'check' => 'archive_integrity',
                        'status' => 'failed',
                        'message' => "Cryptographic SHA-256 checksum mismatch for backup archive [{$filePath}]. Expected [{$expectedSha}], got [{$computedSha}].",
                        'context' => [
                            'backup_id' => $latestSuccessful->id,
                            'expected_sha256' => $expectedSha,
                            'actual_sha256' => $computedSha,
                        ],
                    ];
                    $isHealthy = false;
                } else {
                    $checks[] = [
                        'check' => 'archive_integrity',
                        'status' => 'passed',
                        'message' => 'Archive file exists and cryptographic SHA-256 checksum verified.',
                        'context' => [
                            'backup_id' => $latestSuccessful->id,
                            'checksum_sha256' => $computedSha,
                        ],
                    ];
                }
            }

            // 3. Check: Size Anomaly Detection
            $historicalSuccesses = $modelClass::successful()
                ->where('id', '<', $latestSuccessful->id)
                ->latestCompleted()
                ->take(5)
                ->get();

            if ($historicalSuccesses->count() >= 2 && $latestSuccessful->file_size_bytes > 0) {
                $avgSizeBytes = (int) $historicalSuccesses->avg('file_size_bytes');
                $latestSize = $latestSuccessful->file_size_bytes;

                if ($avgSizeBytes > 0) {
                    $deviationPercent = round((($latestSize - $avgSizeBytes) / $avgSizeBytes) * 100, 1);
                    $threshold = (float) config('scoped-backup.health.size_deviation_threshold_percent', 50);

                    if ($deviationPercent <= -$threshold) {
                        $checks[] = [
                            'check' => 'size_anomaly',
                            'status' => 'warning',
                            'message' => "Backup size dropped by {$deviationPercent}% relative to historical average ({$latestSize} bytes vs avg {$avgSizeBytes} bytes). Investigate potential partial table data loss.",
                            'context' => [
                                'latest_size_bytes' => $latestSize,
                                'historical_avg_bytes' => $avgSizeBytes,
                                'deviation_percent' => $deviationPercent,
                            ],
                        ];
                    } else {
                        $checks[] = [
                            'check' => 'size_anomaly',
                            'status' => 'passed',
                            'message' => "Backup size is within normal historical parameters (Deviation: {$deviationPercent}%).",
                            'context' => [
                                'latest_size_bytes' => $latestSize,
                                'historical_avg_bytes' => $avgSizeBytes,
                            ],
                        ];
                    }
                }
            }
        }

        // 4. Check: Recent Failure Rate
        $recentRuns = $modelClass::latest('id')->take(5)->get();
        if ($recentRuns->isNotEmpty() && $recentRuns->first()->isFailed()) {
            $checks[] = [
                'check' => 'recent_runs',
                'status' => 'warning',
                'message' => "Most recent backup attempt failed: {$recentRuns->first()->error_message}",
                'context' => [
                    'failed_backup_id' => $recentRuns->first()->id,
                    'error_message' => $recentRuns->first()->error_message,
                ],
            ];
        } else {
            $checks[] = [
                'check' => 'recent_runs',
                'status' => 'passed',
                'message' => 'Recent backup runs completed without error.',
            ];
        }

        return new HealthCheckResult($isHealthy, $checks);
    }
}
