<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The connection used by models and the DynamoDb facade when no explicit
    | connection name is given.
    |
    */

    'default' => env('DYNAMODB_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each connection is passed to the AWS SDK's DynamoDbClient. Credentials
    | may be omitted; the AWS SDK will then use its default provider chain
    | (environment variables, shared credentials file, IAM role, etc.).
    |
    */

    'connections' => [

        'default' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'token' => env('AWS_SESSION_TOKEN'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => env('AWS_VERSION', 'latest'),

            // Set this to http://localhost:8000 when using DynamoDB Local.
            'endpoint' => env('DYNAMODB_ENDPOINT'),

            // Optional prefix applied to every table name, e.g. "app_prod_".
            'table_prefix' => env('DYNAMODB_TABLE_PREFIX', ''),

            'http' => [
                'timeout' => (float) env('DYNAMODB_TIMEOUT', 30),
                'connect_timeout' => (float) env('DYNAMODB_CONNECT_TIMEOUT', 10),
            ],

            'retries' => (int) env('DYNAMODB_RETRIES', 3),

            // Options passed to Aws\DynamoDb\Marshaler.
            'marshaler' => [],
        ],

    ],

];
