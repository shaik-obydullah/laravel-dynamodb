# Laravel DynamoDB

A fluent DynamoDB query builder and an Eloquent-like model layer for Laravel. It
wraps the official AWS SDK for PHP, keeps expression placeholders safe, and gives
you a familiar API for CRUD, key queries, filters, indexes, and pagination.

```php
$user = User::find('user#42');

$orders = Order::where('customer_id', '=', 'customer#42')
    ->whereBeginsWith('order_id', '2026-')
    ->filter('status', 'paid')
    ->descending()
    ->limit(25)
    ->get();
```

---

## Features

- Eloquent-like `Model` with attributes, casts, mutators, timestamps, and
  dirty tracking.
- Fluent query builder for DynamoDB `Query` and `Scan` requests.
- Key conditions and filter expressions with automatic `#name` / `:value`
  placeholder management.
- Composite partition/sort keys.
- Secondary index queries with `useIndex()`.
- Projections, consistent reads, limits, descending order, and cursor
  pagination with `LastEvaluatedKey`.
- `chunk()` and `lazy()` for processing large result sets page by page.
- Batch writes (automatic 25-item chunks and `UnprocessedItems` retries).
- Multiple named connections and table prefixes.
- Safe conditional inserts/updates by default; opt into upserts when needed.
- Raw AWS SDK client escape hatch.
- No Laravel application required for unit tests: a custom connection resolver
  can be installed on models.

---

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12 (`illuminate/support` and `illuminate/container`)
- `aws/aws-sdk-php` 3.x
- A DynamoDB table, or [DynamoDB Local](#local-development-with-dynamodb-local)
  for development

---

## Installation

Install the package with Composer:

```bash
composer require shaik/laravel-dynamodb
```

If you are working from a local checkout instead of Packagist, add a path
repository to your application's `composer.json` first:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-dynamodb"
        }
    ]
}
```

Then require it:

```bash
composer require shaik/laravel-dynamodb:@dev
```

Laravel auto-discovers the service provider and `DynamoDb` facade. If you
disabled package discovery, register them manually in `bootstrap/providers.php`:

```php
return [
    // ...
    Shaik\LaravelDynamoDb\DynamoDbServiceProvider::class,
];
```

And add the alias in `config/app.php` if you want the facade:

```php
'aliases' => [
    // ...
    'DynamoDb' => Shaik\LaravelDynamoDb\Facades\DynamoDb::class,
],
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=dynamodb-config
```

---

## Configuration

Add the following variables to your `.env`. Credentials are optional: when they
are omitted the AWS SDK uses its normal provider chain (environment variables,
shared credentials file, ECS task role, EC2 instance profile, etc.).

```dotenv
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_SESSION_TOKEN=
AWS_DEFAULT_REGION=us-east-1

# Optional
DYNAMODB_CONNECTION=default
DYNAMODB_ENDPOINT=
DYNAMODB_TABLE_PREFIX=
DYNAMODB_TIMEOUT=30
DYNAMODB_CONNECT_TIMEOUT=10
DYNAMODB_RETRIES=3
```

`config/dynamodb.php` supports multiple connections:

```php
'connections' => [
    'default' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'table_prefix' => env('DYNAMODB_TABLE_PREFIX', ''),
    ],

    'analytics' => [
        'key' => env('ANALYTICS_AWS_KEY'),
        'secret' => env('ANALYTICS_AWS_SECRET'),
        'region' => 'eu-west-1',
        'table_prefix' => 'analytics_',
    ],
],
```

Select a connection on a model:

```php
class Event extends Model
{
    protected $connection = 'analytics';

    protected $table = 'events';
}
```

Or resolve it directly:

```php
use Shaik\LaravelDynamoDb\Facades\DynamoDb;

DynamoDb::connection('analytics')->table('events')->limit(10)->get();
```

---

## Defining a model

```php
namespace App\Models;

use Shaik\LaravelDynamoDb\Model;

class User extends Model
{
    protected $table = 'users';

    // Partition key attribute name.
    protected $primaryKey = 'id';

    // Sort key attribute name, if the table has a composite key.
    protected $sortKey = null;

    // Allow mass assignment. The default follows Eloquent and guards everything.
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'age' => 'integer',
        'tags' => 'array',
        'settings' => 'array',
        'joined_at' => 'datetime',
    ];
}
```

### Composite keys

DynamoDB tables can have a partition key and a sort key. Configure both:

```php
class Order extends Model
{
    protected $table = 'orders';

    protected $primaryKey = 'customer_id';
    protected $sortKey = 'order_id';

    protected $guarded = [];
}
```

Composite keys are passed as arrays:

```php
$order = Order::find([
    'customer_id' => 'customer#42',
    'order_id' => 'order#1001',
]);
```

### Supported casts

| Cast | Stored as | Returned as |
| --- | --- | --- |
| `int`, `integer` | Number | `int` |
| `float`, `double`, `real` | Number | `float` |
| `bool`, `boolean` | Boolean | `bool` |
| `string` | String | `string` |
| `array` | Native DynamoDB list/map | `array` |
| `json` | JSON string | Decoded value |
| `object` | Native DynamoDB map | `stdClass`/object |
| `collection` | Native DynamoDB list | `Illuminate\Support\Collection` |
| `date` | `Y-m-d` string | `Carbon` |
| `datetime` | `Y-m-d H:i:s` string | `Carbon` |
| `immutable_date`, `immutable_datetime` | Date string | `CarbonImmutable` |
| `timestamp` | Unix timestamp number | `int` |
| `string_set`, `number_set` | DynamoDB `SS`/`NS` set | `array` |

Timestamps (`created_at`, `updated_at`) are enabled by default. Disable them
with `protected $timestamps = false;`.

---

## Creating, updating, and deleting

```php
use App\Models\User;

$user = new User;
$user->id = 'user#42';
$user->name = 'Alice';
$user->active = true;
$user->tags = ['admin', 'editor'];
$user->save();

// fill() respects $fillable/$guarded; forceFill() bypasses mass assignment.
$user = User::create([
    'id' => 'user#43',
    'name' => 'Bob',
    'active' => true,
]);

$user->name = 'Robert';
$user->save(); // only dirty attributes are sent in an UpdateItem request

$user->delete();

$user->refresh(); // reload the item from DynamoDB
$fresh = $user->fresh(); // return a new instance, or null if it was deleted
```

### Conditional writes

New models use `attribute_not_exists(<key>)` so `save()` cannot silently
overwrite an existing item. Existing models use `attribute_exists(<key>)` so an
`UpdateItem` cannot recreate a deleted item as a partial row.

To force an upsert:

```php
$user->save(['overwrite' => true]);
```

### Timestamps

With timestamps enabled, new items receive `created_at` and `updated_at`, and an
existing item receives a fresh `updated_at` on save. Both are stored as
`Y-m-d H:i:s` strings and returned as `Carbon` instances.

---

## Querying

The builder distinguishes between **key conditions** and **filters**, because
DynamoDB treats them differently:

- `where()` conditions build `KeyConditionExpression` and use table/index keys.
- `filter()` conditions build `FilterExpression` and run after items are read.
- A builder with no key conditions automatically runs a `Scan`.

### Query by key

```php
$user = User::where('id', '=', 'user#42')->first();

$user = User::find('user#42');
$user = User::findOrFail('user#42'); // throws ModelNotFoundException
```

With a sort key:

```php
$orders = Order::where('customer_id', '=', 'customer#42')
    ->whereBetween('order_id', ['2026-01-01', '2027-01-01'])
    ->descending()
    ->limit(50)
    ->get();
```

Key-condition convenience methods:

```php
->whereBetween('order_id', ['2026-01-01', '2026-12-31'])
->whereBeginsWith('order_id', '2026-')
->orWhere('status', '=', 'pending')
```

DynamoDB allows **one** condition on the partition key and **one** condition on
the sort key, and restricts sort-key operators to `=`, `<`, `<=`, `>`, `>=`,
`BETWEEN`, and `begins_with`. Conditions such as `contains`, `in`,
`attribute_exists`, and `attribute_not_exists` belong in filters. The builder
does not hide those server-side rules; invalid key expressions are rejected by
DynamoDB.

### Filters

Use filters for non-key attributes:

```php
$paid = Order::where('customer_id', '=', 'customer#42')
    ->filter('status', '=', 'paid')
    ->filterBetween('total', [100, 500])
    ->get();
```

Scan with a filter:

```php
$admins = User::scan()
    ->filter('role', '=', 'admin')
    ->filter('active', '=', true)
    ->get();
```

> Filters are applied **after** DynamoDB reads the items. They reduce the
> returned result size, not the read capacity consumed. Design keys and indexes
> around your access patterns.

Available filter helpers: `filterIn()`, `filterBetween()`, `filterBeginsWith()`,
`filterContains()`, `filterAttributeExists()`, `filterAttributeNotExists()`,
and `orFilter()`.

### Nested conditions

```php
$users = User::scan()
    ->filter(function ($query) {
        $query->where('role', '=', 'admin')
              ->orWhere('role', '=', 'editor');
    })
    ->filter('active', '=', true)
    ->get();
```

### Secondary indexes

```php
$users = User::query()
    ->useIndex('status-index')
    ->where('status', '=', 'active')
    ->where('created_at', '>', '2026-01-01')
    ->get();
```

When querying an index, `where()` conditions must match the index key schema.
Use `filter()` for attributes that are not part of that index key.

### Projection, consistency, limit, and order

```php
$users = User::query()
    ->select(['id', 'name', 'status']) // ProjectionExpression
    ->useIndex('status-index')
    ->where('status', '=', 'active')
    ->consistentRead()
    ->limit(20)
    ->descending()
    ->get();
```

DynamoDB can only sort by the sort key of the table or index, so `orderBy()`
accepts a column for readability but only applies the direction:

```php
->orderBy('created_at', 'desc') // same as ->descending()
```

### First, count, exists, pluck, value

```php
$first = User::where('id', '=', 'user#42')->first();
$total = User::scan()->filter('active', '=', true)->count();
$exists = User::where('id', '=', 'user#42')->exists();
$names = User::scan()->pluck('name');
$name = User::where('id', '=', 'user#42')->value('name');
```

`count()` follows `LastEvaluatedKey` until all matching items have been counted.
On a large table this can consume a lot of read capacity.

---

## Pagination and large results

DynamoDB paginates with a `LastEvaluatedKey`, not offset pages. Every `get()`
stores the key from the response:

```php
$query = Order::where('customer_id', '=', 'customer#42')->limit(25);

$page = $query->get();
$lastKey = $query->getLastEvaluatedKey();

if ($query->hasMorePages()) {
    $nextPage = $query->startAt($lastKey)->get();
}
```

`LastEvaluatedKey` is returned as a plain PHP array and can be sent back to the
client (for example, as a base64-encoded cursor) and passed to `startAt()` later.

### chunk()

```php
Order::where('customer_id', '=', 'customer#42')
    ->chunk(100, function ($orders) {
        foreach ($orders as $order) {
            // process one page
        }

        // return false to stop early
    });
```

### lazy()

```php
foreach (User::scan()->filter('active', '=', true)->lazy(500) as $user) {
    echo $user->name;
}
```

`lazy()` fetches pages on demand and yields one model at a time.

---

## Batch writes

Insert up to 25 items per request. Larger arrays are split automatically, and
unprocessed items are retried with backoff:

```php
use Shaik\LaravelDynamoDb\Facades\DynamoDb;

DynamoDb::table('audit_logs')->insert([
    [
        'id' => 'log#1',
        'message' => 'User signed in',
    ],
    [
        'id' => 'log#2',
        'message' => 'User signed out',
    ],
]);
```

Messages and other values passed to a raw table builder are marshaled as-is; the
builder has no model casts.

Delete every item matching a query in batches of 25:

```php
$deleted = Order::where('customer_id', '=', 'customer#42')->delete();
```

Batch writes do **not** support conditional expressions. Use model `save()` when
you need conditions.

---

## Raw AWS SDK access

The package intentionally leaves advanced features available through the
official SDK:

```php
use Shaik\LaravelDynamoDb\Facades\DynamoDb;

$client = DynamoDb::client();

$client->describeTable(['TableName' => 'users']);

// Connection __call also forwards straight to the AWS client.
$result = DynamoDb::connection()->transactWriteItems([
    'TransactItems' => [
        // ...
    ],
]);
```

The raw client returns `Aws\Result` objects with DynamoDB AttributeValue maps
(`['S' => '...']`, `['N' => '42']`, and so on). The fluent builder and model
layer unmarshal those maps into normal PHP values for you.

---

## Local development with DynamoDB Local

Start the local server:

```bash
docker compose up -d
```

Point the package at it:

```dotenv
AWS_ACCESS_KEY_ID=fake
AWS_SECRET_ACCESS_KEY=fake
AWS_DEFAULT_REGION=us-east-1
DYNAMODB_ENDPOINT=http://127.0.0.1:8000
```

Create a table with the AWS CLI:

```bash
aws dynamodb create-table \
  --table-name users \
  --attribute-definitions AttributeName=id,AttributeType=S \
  --key-schema AttributeName=id,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST \
  --endpoint-url http://127.0.0.1:8000
```

If you do not have the AWS CLI installed:

```bash
docker run --rm -it --network host amazon/aws-cli \
  dynamodb create-table \
  --table-name users \
  --attribute-definitions AttributeName=id,AttributeType=S \
  --key-schema AttributeName=id,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST \
  --endpoint-url http://127.0.0.1:8000
```

---

## Testing

```bash
composer install
composer test
```

The test suite uses the AWS SDK's `MockHandler`, so it never touches AWS.

With Docker only:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD":/app -w /app composer:2 install

docker run --rm -v "$PWD":/app -w /app php:8.4-cli \
  php vendor/bin/phpunit
```

To test models outside a Laravel application, install a connection resolver:

```php
use Shaik\LaravelDynamoDb\Connection;
use App\Models\User;

User::setConnectionResolver(fn () => $connection);

$user = User::find('user#42');
```

---

## IAM permissions

A minimal policy for the operations in this package:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "dynamodb:GetItem",
                "dynamodb:PutItem",
                "dynamodb:UpdateItem",
                "dynamodb:DeleteItem",
                "dynamodb:Query",
                "dynamodb:Scan",
                "dynamodb:BatchWriteItem",
                "dynamodb:DescribeTable"
            ],
            "Resource": [
                "arn:aws:dynamodb:us-east-1:123456789012:table/users",
                "arn:aws:dynamodb:us-east-1:123456789012:table/users/index/*"
            ]
        }
    ]
}
```

Add `dynamodb:BatchGetItem` or `dynamodb:TransactWriteItems` if you use those
operations through the raw client.

---

## How it works

```text
config/dynamodb.php
        │
        ▼
DynamoDbManager ──► Connection ──► Aws\DynamoDb\DynamoDbClient
                           │
                           ├── Query\Builder
                           │      ├── ConditionBuilder (key conditions)
                           │      ├── ConditionBuilder (filters)
                           │      └── ExpressionContext (#names, :values)
                           │
                           └── Model (CRUD, casts, dirty tracking,
                                      timestamps, conditional writes)
```

`ExpressionContext` allocates unique placeholders for key conditions, filters,
and projections in a single request, then resets for the next request. This
avoids the common DynamoDB error where a placeholder is reused with the wrong
attribute or value.

---

## Important DynamoDB limitations

This package does not try to hide DynamoDB's distributed-system behavior:

- There is no auto-incrementing primary key. Assign keys yourself (`user#42`,
  UUIDs, ULIDs, and so on).
- There are no joins, relationships, migrations, or table creation.
- A single item is limited to **400 KB**.
- `Query` requires a partition key; use `Scan` (and filter carefully) otherwise.
- Filters run after reads and do not reduce consumed read capacity.
- Scans read the whole table or index and can be expensive.
- DynamoDB allows only one condition on the partition key and a limited set of
  sort-key operators. Use `filter()` for everything else.
- `IN` is not valid in a key condition expression. Use it in filters.
- Only the table/index sort key can control ordering; `orderBy()` only changes
  direction.
- Consistent reads are not supported on global secondary indexes.
- Null is not a stored value. Omit the attribute, or use
  `attribute_exists`/`attribute_not_exists`.
- Batch writes are non-conditional. Unprocessed items are retried with backoff,
  but a batch write still cannot enforce conditions.
- Timestamps are stored as `Y-m-d H:i:s` strings, not DynamoDB-native dates.
- Transactions, streams, TTL configuration, backups, and table provisioning are
  intentionally left to the AWS SDK or infrastructure tooling.

---

## Project layout

```text
.
├── config/
│   └── dynamodb.php
├── src/
│   ├── Connection.php
│   ├── DynamoDbManager.php
│   ├── DynamoDbServiceProvider.php
│   ├── Model.php
│   ├── Exceptions/
│   │   ├── DynamoDbException.php
│   │   └── ModelNotFoundException.php
│   ├── Facades/
│   │   └── DynamoDb.php
│   └── Query/
│       ├── Builder.php
│       ├── ConditionBuilder.php
│       └── ExpressionContext.php
├── tests/
├── docker-compose.yml
├── composer.json
└── phpunit.xml
```

---

## License

MIT. See [LICENSE](LICENSE).
