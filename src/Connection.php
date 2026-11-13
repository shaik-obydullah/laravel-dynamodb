<?php

namespace Shaik\LaravelDynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Shaik\LaravelDynamoDb\Exceptions\DynamoDbException;
use Shaik\LaravelDynamoDb\Query\Builder;

/**
 * A thin wrapper around one Aws\DynamoDb\DynamoDbClient.
 */
class Connection
{
    public function __construct(
        protected DynamoDbClient $client,
        protected Marshaler $marshaler,
        protected string $tablePrefix = ''
    ) {
    }

    public function client(): DynamoDbClient
    {
        return $this->client;
    }

    public function marshaler(): Marshaler
    {
        return $this->marshaler;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function tableName(string $table): string
    {
        return $this->tablePrefix.$table;
    }

    /**
     * Start a fluent query against a table.
     */
    public function table(string $table): Builder
    {
        return new Builder($this, $table);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, array>
     */
    public function marshalItem(array $item): array
    {
        return $this->marshaler->marshalItem($item);
    }

    /**
     * @param  array<string, array>  $item
     * @return array<string, mixed>
     */
    public function unmarshalItem(array $item): array
    {
        return $this->marshaler->unmarshalItem($item);
    }

    /**
     * @param  array<int, array<string, array>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function unmarshalItems(array $items): array
    {
        return array_map(fn (array $item): array => $this->unmarshalItem($item), $items);
    }

    /**
     * Send batch write requests, retrying DynamoDB's UnprocessedItems.
     *
     * @param  array<int, array<string, mixed>>  $requests
     */
    public function batchWriteItem(string $table, array $requests): void
    {
        if ($requests === []) {
            return;
        }

        $table = $this->tableName($table);
        $pending = $requests;
        $attempt = 0;

        while ($pending !== []) {
            if ($attempt >= 5) {
                throw new DynamoDbException("DynamoDB left items unprocessed after 5 attempts for table [{$table}].");
            }

            if ($attempt > 0) {
                usleep(min(1_000_000, 100_000 * (2 ** ($attempt - 1))));
            }

            $next = [];

            foreach (array_chunk($pending, 25) as $chunk) {
                $result = $this->client->batchWriteItem([
                    'RequestItems' => [
                        $table => $chunk,
                    ],
                ]);

                foreach ($result['UnprocessedItems'][$table] ?? [] as $request) {
                    $next[] = $request;
                }
            }

            $pending = $next;
            $attempt++;
        }
    }

    /**
     * Allow any AWS SDK client method to be called directly on the connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->client->{$method}(...$parameters);
    }
}
