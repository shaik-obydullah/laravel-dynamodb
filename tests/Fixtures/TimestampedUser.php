<?php

namespace Shaik\LaravelDynamoDb\Tests\Fixtures;

use Shaik\LaravelDynamoDb\Model;

class TimestampedUser extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'id';

    protected $guarded = [];
}
