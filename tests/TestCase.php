<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KaziSTM\LaravelScopedBackup\ScopedBackupServiceProvider;
use KaziSTM\LaravelScopedBackup\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        if (! class_exists('App\Models\User')) {
            class_alias(User::class, 'App\Models\User');
        }

        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ScopedBackupServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.key', 'base64:yk3VUJyVo5pVOmt0iGY2Zoh4dhVO2bpNu7amJk1k5xY=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('scoped-backup.table_name', 'database_backups');
        $app['config']->set('scoped-backup.storage.disk', 'local');
        $app['config']->set('scoped-backup.storage.path', 'backups');
    }

    protected function setUpDatabase(): void
    {
        // 1. Run package migration
        $backupMigration = include __DIR__.'/../database/migrations/create_backup_records_table.php.stub';
        $backupMigration->up();

        // 2. Setup users table for scoped tests
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->timestamps();
            });
        }
    }
}

// Global alias for compatibility with test imports
if (! class_exists('Tests\TestCase')) {
    class_alias(TestCase::class, 'Tests\TestCase');
}
