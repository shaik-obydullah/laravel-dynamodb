<?php

namespace Shaik\LaravelDynamoDb\Tests\Fixtures;

use Shaik\LaravelDynamoDb\Model;

class User extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $timestamps = false;

    protected $casts = [
        'active' => 'boolean',
        'age' => 'integer',
        'tags' => 'array',
        'settings' => 'array',
        'joined_at' => 'datetime',
    ];
}
