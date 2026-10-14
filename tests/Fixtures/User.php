<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = [
        'password',
    ];
}
