<?php

declare(strict_types=1);
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

return [
    /*
    |--------------------------------------------------------------------------
    | Backup History Tracking Table & Model
    |--------------------------------------------------------------------------
    |
    | Database table and model used to record backup executions, status,
    | durations, checksums, and soft-pruned history.
    | Defaults to 'database_backups' for 100% backwards compatibility with
    | existing application installations.
    |
    */
    'table_name' => env('BACKUP_TABLE_NAME', 'database_backups'),
    'model' => BackupRecord::class,

    /*
    |--------------------------------------------------------------------------
    | Database Connection & Dumper Settings
    |--------------------------------------------------------------------------
    */
    'database' => [
        'connection' => env('BACKUP_DATABASE_CONNECTION', env('DATABASE_BACKUP_CONNECTION', config('database.default', 'pgsql'))),
        'timeout' => (int) env('BACKUP_TIMEOUT', env('DATABASE_BACKUP_TIMEOUT', 600)),
        'drivers' => [
            'pgsql' => [
                'dump_binary' => env('PG_DUMP_BINARY', 'pg_dump'),
                'restore_binary' => env('PG_RESTORE_BINARY', 'pg_restore'),
                'format' => 'custom', // PostgreSQL custom compressed binary (-F c)
                'pre_publication_verify' => true,
            ],
            'mysql' => [
                'dump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
                'restore_binary' => env('MYSQL_BINARY', 'mysql'),
                'single_transaction' => true,
                'quick' => true,
                'routines' => true,
                'triggers' => true,
                'events' => true,
                'default_character_set' => 'utf8mb4',
            ],
            'mariadb' => [
                'dump_binary' => env('MARIADB_DUMP_BINARY', 'mariadb-dump'),
                'restore_binary' => env('MARIADB_BINARY', 'mariadb'),
                'fallback_to_mysql' => true,
                'single_transaction' => true,
                'quick' => true,
                'routines' => true,
                'triggers' => true,
                'events' => true,
                'default_character_set' => 'utf8mb4',
            ],
            'sqlite' => [
                'busy_timeout' => 5000,
                'use_native_backup' => true,
            ],
        ],
        // Backwards compatibility alias
        'dumpers' => [
            'pgsql' => [
                'dump_binary' => env('PG_DUMP_BINARY', 'pg_dump'),
                'restore_binary' => env('PG_RESTORE_BINARY', 'pg_restore'),
                'format' => 'custom',
                'pre_publication_verify' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem Inclusion / Exclusion
    |--------------------------------------------------------------------------
    */
    'filesystem' => [
        'enabled' => env('BACKUP_INCLUDE_FILES', false),
        'include_paths' => [],
        'exclude_paths' => [
            '*.tmp',
            '.git*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Disk & Directory
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('BACKUP_STORAGE_DISK', env('DATABASE_BACKUP_DISK', 'local')),
        'path' => env('BACKUP_STORAGE_PATH', env('DATABASE_BACKUP_PATH', 'backups')),
        'temp_staging_dir' => storage_path('framework/cache/backups'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Policy (Soft-Pruning with Audit Preservation)
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'limit' => (int) env('BACKUP_RETENTION', env('DATABASE_BACKUP_RETENTION', 7)),
        'protect_last_successful' => true,
        'preserve_audit_records' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoped Dataset Defaults
    |--------------------------------------------------------------------------
    */
    'scoped' => [
        'chunk_size' => (int) env('BACKUP_SCOPED_CHUNK_SIZE', 100),
        'storage_path' => env('BACKUP_SCOPED_STORAGE_PATH', database_path('seeders/data/datasets')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Monitoring & TTL Thresholds
    |--------------------------------------------------------------------------
    */
    'health' => [
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 28),
        'size_deviation_threshold_percent' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'enabled' => env('BACKUP_NOTIFICATIONS_ENABLED', false),
        'channels' => ['mail', 'webhook'],
        'mail' => [
            'to' => env('BACKUP_NOTIFICATION_EMAIL'),
        ],
        'webhook' => [
            'url' => env('BACKUP_DISCORD_WEBHOOK_URL'),
            'format' => 'discord', // 'discord', 'generic_json'
        ],
        'events' => [
            'backup_failed' => true,
            'cleanup_failed' => true,
            'unhealthy_detected' => true,
            'backup_succeeded' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule Settings
    |--------------------------------------------------------------------------
    */
    'schedule' => [
        'enabled' => false, // Application controls scheduler in routes/console.php
        'time' => env('BACKUP_SCHEDULE_TIME', env('DATABASE_BACKUP_SCHEDULE_TIME', '01:00')),
    ],
];
