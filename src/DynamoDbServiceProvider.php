<?php

namespace Shaik\LaravelDynamoDb;

use Illuminate\Support\ServiceProvider;

class DynamoDbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dynamodb.php', 'dynamodb');

        $this->app->singleton('dynamodb', function ($app): DynamoDbManager {
            return new DynamoDbManager($app, $app['config']->get('dynamodb', []));
        });

        $this->app->alias('dynamodb', DynamoDbManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/dynamodb.php' => $this->app->configPath('dynamodb.php'),
            ], 'dynamodb-config');
        }
    }
}
