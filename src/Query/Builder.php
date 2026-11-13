<?php

namespace Shaik\LaravelDynamoDb\Query;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Shaik\LaravelDynamoDb\Connection;
use Shaik\LaravelDynamoDb\Model;

/**
 * A fluent query builder for DynamoDB Query and Scan requests.
 */
class Builder
{
    protected Connection $connection;

    protected string $table;

    /** @var class-string<Model>|null */
    protected ?string $model = null;

    /** @var array<int, string> */
    protected array $keyNames = [];

    protected ?string $index = null;

    protected bool $scan = false;

    protected bool $consistentRead = false;

    /** @var array<int, string> */
    protected array $columns = [];

    protected ?int $limit = null;

    protected ?string $order = null;

    protected ?array $startKey = null;

    protected ?array $lastEvaluatedKey = null;

    protected ConditionBuilder $keyConditions;

    protected ConditionBuilder $filterConditions;

    protected ExpressionContext $expressions;

    public function __construct(Connection $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = $table;
        $this->expressions = new ExpressionContext($connection->marshaler());
        $this->keyConditions = new ConditionBuilder($this->expressions);
        $this->filterConditions = new ConditionBuilder($this->expressions);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @param  array<int, string>  $keyNames
     */
    public function setKeyNames(array $keyNames): static
    {
        $this->keyNames = array_values($keyNames);

        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Add a key condition. On a Query request these become part of
     * KeyConditionExpression. If the builder has no key conditions it runs a
     * Scan request instead.
     */
    public function where(string|Closure $attribute, mixed $operator = null, mixed $value = null): static
    {
        $this->keyConditions->where(...func_get_args());

        return $this;
    }

    public function orWhere(string|Closure $attribute, mixed $operator = null, mixed $value = null): static
    {
        if ($attribute instanceof Closure) {
            $this->keyConditions->where($attribute, null, null, 'or');
        } elseif (func_num_args() === 2) {
            $this->keyConditions->where($attribute, '=', $operator, 'or');
        } else {
            $this->keyConditions->where($attribute, $operator, $value, 'or');
        }

        return $this;
    }

    public function whereBetween(string $attribute, array $values): static
    {
        return $this->where($attribute, 'between', $values);
    }

    public function whereBeginsWith(string $attribute, mixed $value): static
    {
        return $this->where($attribute, 'begins_with', $value);
    }

    /**
     * Add a filter condition. Filters are applied after key conditions and do
     * not reduce consumed read capacity.
     */
    public function filter(string|Closure $attribute, mixed $operator = null, mixed $value = null): static
    {
        $this->filterConditions->where(...func_get_args());

        return $this;
    }

    public function orFilter(string|Closure $attribute, mixed $operator = null, mixed $value = null): static
    {
        if ($attribute instanceof Closure) {
            $this->filterConditions->where($attribute, null, null, 'or');
        } elseif (func_num_args() === 2) {
            $this->filterConditions->where($attribute, '=', $operator, 'or');
        } else {
            $this->filterConditions->where($attribute, $operator, $value, 'or');
        }

        return $this;
    }

    public function filterIn(string $attribute, array $values): static
    {
        return $this->filter($attribute, 'in', $values);
    }

    public function filterBetween(string $attribute, array $values): static
    {
        return $this->filter($attribute, 'between', $values);
    }

    public function filterBeginsWith(string $attribute, mixed $value): static
    {
        return $this->filter($attribute, 'begins_with', $value);
    }

    public function filterContains(string $attribute, mixed $value): static
    {
        return $this->filter($attribute, 'contains', $value);
    }

    public function filterAttributeExists(string $attribute): static
    {
        return $this->filter($attribute, 'attribute_exists', null);
    }

    public function filterAttributeNotExists(string $attribute): static
    {
        return $this->filter($attribute, 'attribute_not_exists', null);
    }

    /**
     * Query a secondary index instead of the base table.
     */
    public function useIndex(?string $index = null): static
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Force a Scan request even when key conditions are present.
     */
    public function scan(bool $value = true): static
    {
        $this->scan = $value;

        return $this;
    }

    public function query(): static
    {
        $this->scan = false;

        return $this;
    }

    public function consistentRead(bool $value = true): static
    {
        $this->consistentRead = $value;

        return $this;
    }

    /**
     * @param  array<int, string>|string  $columns
     */
    public function select(array|string $columns = ['*']): static
    {
        if ($columns === '*' || $columns === ['*'] || $columns === []) {
            $this->columns = [];

            return $this;
        }

        $this->columns = is_array($columns) ? array_values($columns) : [$columns];

        return $this;
    }

    /**
     * @param  array<int, string>|string  $columns
     */
    public function addSelect(array|string $columns): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->columns = array_values(array_unique(array_merge($this->columns, $columns)));

        return $this;
    }

    public function limit(int $limit): static
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The limit must be greater than zero.');
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * DynamoDB only supports sorting by the table or index sort key.
     * The column argument is accepted for readability.
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('The sort direction must be either [asc] or [desc].');
        }

        $this->order = $direction;

        return $this;
    }

    public function ascending(): static
    {
        $this->order = 'asc';

        return $this;
    }

    public function descending(): static
    {
        $this->order = 'desc';

        return $this;
    }

    /**
     * Set the ExclusiveStartKey, normally from a previous page's
     * getLastEvaluatedKey() result.
     */
    public function startAt(array|string|int $key): static
    {
        $this->startKey = is_array($key) ? $key : $this->normalizeKey($key);

        return $this;
    }

    public function getLastEvaluatedKey(): ?array
    {
        return $this->lastEvaluatedKey;
    }

    public function hasMorePages(): bool
    {
        return $this->lastEvaluatedKey !== null;
    }

    /**
     * Build the raw DynamoDB request parameters.
     *
     * @return array<string, mixed>
     */
    public function toDynamoDbQuery(): array
    {
        $this->expressions->reset();

        $scan = $this->usesScan();
        $keyExpression = $scan ? null : $this->keyConditions->toExpression();
        $filterExpression = $this->filterConditions->toExpression();

        $params = [
            'TableName' => $this->connection->tableName($this->table),
        ];

        if ($this->index !== null) {
            $params['IndexName'] = $this->index;
        }

        if ($keyExpression !== null) {
            $params['KeyConditionExpression'] = $keyExpression;
        }

        if ($filterExpression !== null) {
            $params['FilterExpression'] = $filterExpression;
        }

        if ($this->columns !== []) {
            $params['ProjectionExpression'] = implode(', ', array_map(
                fn (string $column): string => $this->expressions->name($column),
                $this->columns
            ));
        }

        if (($names = $this->expressions->names()) !== []) {
            $params['ExpressionAttributeNames'] = $names;
        }

        if (($values = $this->expressions->values()) !== []) {
            $params['ExpressionAttributeValues'] = $values;
        }

        if ($this->consistentRead) {
            $params['ConsistentRead'] = true;
        }

        if ($this->limit !== null) {
            $params['Limit'] = $this->limit;
        }

        if (! $scan && $this->order !== null) {
            $params['ScanIndexForward'] = $this->order === 'asc';
        }

        if ($this->startKey !== null) {
            $params['ExclusiveStartKey'] = $this->connection->marshalItem($this->startKey);
        }

        return $params;
    }

    /**
     * @return Collection<int, Model|array<string, mixed>>
     */
    public function get(): Collection
    {
        $params = $this->toDynamoDbQuery();
        $result = $this->usesScan()
            ? $this->connection->client()->scan($params)
            : $this->connection->client()->query($params);

        $this->lastEvaluatedKey = isset($result['LastEvaluatedKey'])
            ? $this->connection->unmarshalItem($result['LastEvaluatedKey'])
            : null;

        $items = $this->connection->unmarshalItems($result['Items'] ?? []);

        return new Collection(array_map(fn (array $item): mixed => $this->newModel($item), $items));
    }

    /**
     * @return Model|array<string, mixed>|null
     */
    public function first(): mixed
    {
        $query = clone $this;
        $query->limit(1);

        return $query->get()->first();
    }

    /**
     * @param  array<string, mixed>|string|int  $key
     * @return Model|array<string, mixed>|null
     */
    public function find(array|string|int $key, array|string $columns = ['*']): mixed
    {
        if ($this->keyNames === []) {
            throw new InvalidArgumentException('No key names have been configured on this query builder.');
        }

        if (is_array($key)) {
            foreach ($this->keyNames as $name) {
                if (! array_key_exists($name, $key)) {
                    throw new InvalidArgumentException("The key [{$name}] is missing from the composite key.");
                }

                $this->where($name, '=', $key[$name]);
            }
        } else {
            if (count($this->keyNames) !== 1) {
                throw new InvalidArgumentException('A composite-key model requires an array key.');
            }

            $this->where($this->keyNames[0], '=', $key);
        }

        return $this->select($columns)->first();
    }

    /**
     * @param  array<string, mixed>|string|int  $key
     * @return Model|array<string, mixed>
     */
    public function findOrFail(array|string|int $key, array|string $columns = ['*']): mixed
    {
        $result = $this->find($key, $columns);

        if ($result === null) {
            throw new \Shaik\LaravelDynamoDb\Exceptions\ModelNotFoundException(
                $this->model ?? $this->table,
                $key
            );
        }

        return $result;
    }

    /**
     * @return Collection<int, mixed>
     */
    public function pluck(string $column, ?string $key = null): Collection
    {
        $columns = $key === null ? [$column] : [$column, $key];
        $results = (clone $this)->select($columns)->get();

        return $key === null
            ? $results->pluck($column)
            : $results->pluck($column, $key);
    }

    public function value(string $column): mixed
    {
        $result = (clone $this)->select([$column])->first();

        if ($result === null) {
            return null;
        }

        return $result instanceof Model ? $result->getAttribute($column) : ($result[$column] ?? null);
    }

    /**
     * Count every matching item, following LastEvaluatedKey across pages.
     */
    public function count(): int
    {
        $query = clone $this;
        $query->columns = [];

        $params = $query->toDynamoDbQuery();
        $params['Select'] = 'COUNT';

        $total = 0;
        $startKey = null;

        do {
            if ($startKey !== null) {
                $params['ExclusiveStartKey'] = $this->connection->marshalItem($startKey);
            }

            $result = $query->usesScan()
                ? $this->connection->client()->scan($params)
                : $this->connection->client()->query($params);

            $total += (int) ($result['Count'] ?? 0);

            $startKey = isset($result['LastEvaluatedKey'])
                ? $this->connection->unmarshalItem($result['LastEvaluatedKey'])
                : null;
        } while ($startKey !== null);

        return $total;
    }

    public function exists(): bool
    {
        return (clone $this)->limit(1)->get()->isNotEmpty();
    }

    /**
     * Iterate through results one page at a time.
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The chunk size must be greater than zero.');
        }

        $startKey = $this->startKey;

        do {
            $query = clone $this;
            $query->limit($count);

            if ($startKey !== null) {
                $query->startAt($startKey);
            }

            $items = $query->get();

            if ($items->isEmpty()) {
                break;
            }

            if ($callback($items) === false) {
                return false;
            }

            $startKey = $query->getLastEvaluatedKey();
        } while ($startKey !== null);

        return true;
    }

    /**
     * Lazily yield every matching item, fetching pages on demand.
     *
     * @return Generator<int, Model|array<string, mixed>>
     */
    public function lazy(int $pageSize = 100): Generator
    {
        $startKey = $this->startKey;

        do {
            $query = clone $this;
            $query->limit($pageSize);

            if ($startKey !== null) {
                $query->startAt($startKey);
            }

            foreach ($query->get() as $item) {
                yield $item;
            }

            $startKey = $query->getLastEvaluatedKey();
        } while ($startKey !== null);
    }

    /**
     * Delete every matching item in batches of 25.
     */
    public function delete(): int
    {
        if ($this->keyNames === []) {
            throw new InvalidArgumentException('No key names have been configured on this query builder.');
        }

        $deleted = 0;
        $keyNames = $this->keyNames;

        $this->select($keyNames)->chunk(25, function (Collection $items) use (&$deleted, $keyNames): void {
            $requests = [];

            foreach ($items as $item) {
                $attributes = $item instanceof Model ? $item->getAttributes() : $item;
                $key = [];

                foreach ($keyNames as $name) {
                    $key[$name] = $item instanceof Model
                        ? $item->getAttributeForDynamoDb($name)
                        : ($attributes[$name] ?? null);
                }

                $requests[] = [
                    'DeleteRequest' => [
                        'Key' => $this->connection->marshalItem($key),
                    ],
                ];
            }

            $this->connection->batchWriteItem($this->table, $requests);
            $deleted += count($requests);
        });

        return $deleted;
    }

    /**
     * Insert up to 25 items per batch write request. Larger arrays are split
     * automatically.
     *
     * @param  array<int, array<string, mixed>|Model>  $items
     */
    public function insert(array $items): bool
    {
        $requests = [];

        foreach ($items as $item) {
            $attributes = $item instanceof Model
                ? $item->getAttributesForDynamoDb()
                : $item;

            $requests[] = [
                'PutRequest' => [
                    'Item' => $this->connection->marshalItem($attributes),
                ],
            ];
        }

        $this->connection->batchWriteItem($this->table, $requests);

        return true;
    }

    protected function usesScan(): bool
    {
        return $this->scan || $this->keyConditions->isEmpty();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function newModel(array $attributes): mixed
    {
        if ($this->model !== null) {
            return $this->model::newFromDynamoDb($attributes);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeKey(string|int $key): array
    {
        if (count($this->keyNames) !== 1) {
            throw new InvalidArgumentException('A composite-key table requires an array key.');
        }

        return [$this->keyNames[0] => $key];
    }
}
