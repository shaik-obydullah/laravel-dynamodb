<?php

namespace Shaik\LaravelDynamoDb\Facades;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Support\Facades\Facade;
use Shaik\LaravelDynamoDb\Connection;
use Shaik\LaravelDynamoDb\Query\Builder;

/**
 * @method static Connection connection(string|null $name = null)
 * @method static Builder table(string $table)
 * @method static DynamoDbClient client()
 * @method static string getDefaultConnection()
 * @method static void setDefaultConnection(string $name)
 * @method static void purge(string|null $name = null)
 * @method static mixed __call(string $method, array $parameters)
 *
 * @see \Shaik\LaravelDynamoDb\DynamoDbManager
 */
class DynamoDb extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'dynamodb';
    }
}
