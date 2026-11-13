<?php

namespace Shaik\LaravelDynamoDb;

use ArrayAccess;
use Aws\DynamoDb\SetValue;
use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonSerializable;
use Shaik\LaravelDynamoDb\Exceptions\DynamoDbException;
use Shaik\LaravelDynamoDb\Exceptions\ModelNotFoundException;
use Shaik\LaravelDynamoDb\Query\Builder;

/**
 * An Eloquent-like model backed by a DynamoDB table.
 */
class Model implements ArrayAccess, JsonSerializable
{
    /** @var string|null */
    protected $connection;

    /** @var string|null */
    protected $table;

    /** @var string */
    protected $primaryKey = 'id';

    /** @var string|null */
    protected $sortKey;

    /** @var array<int, string> */
    protected $fillable = [];

    /** @var array<int, string> */
    protected $guarded = ['*'];

    /** @var array<string, string> */
    protected $casts = [];

    /** @var bool */
    protected $timestamps = true;

    /** @var string */
    protected $createdAt = 'created_at';

    /** @var string */
    protected $updatedAt = 'updated_at';

    /** @var array<string, mixed> */
    protected $attributes = [];

    /** @var array<string, mixed> */
    protected $original = [];

    /** @var bool */
    protected $exists = false;

    /** @var Closure|null */
    protected static $connectionResolver;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->fill($attributes);
        $this->exists = $exists;
        $this->syncOriginal();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function newFromDynamoDb(array $attributes): static
    {
        $model = new static([], true);
        $model->setRawAttributes($attributes, true);

        return $model;
    }

    public static function setConnectionResolver(?Closure $resolver): void
    {
        static::$connectionResolver = $resolver;
    }

    public static function resolveConnection(?string $name = null): Connection
    {
        if (static::$connectionResolver instanceof Closure) {
            return (static::$connectionResolver)($name);
        }

        return Container::getInstance()->make(DynamoDbManager::class)->connection($name);
    }

    public function getConnection(): Connection
    {
        return static::resolveConnection($this->connection);
    }

    public function getConnectionName(): ?string
    {
        return $this->connection;
    }

    public function getTable(): string
    {
        if ($this->table !== null) {
            return $this->table;
        }

        return Str::snake(Str::pluralStudly(class_basename(static::class)));
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getSortKey(): ?string
    {
        return $this->sortKey;
    }

    /**
     * @return array<int, string>
     */
    public function getKeyNames(): array
    {
        return array_values(array_filter([$this->getKeyName(), $this->getSortKey()]));
    }

    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * @return array<string, mixed>
     */
    public function getKeyValues(): array
    {
        $key = [];

        foreach ($this->getKeyNames() as $name) {
            $key[$name] = array_key_exists($name, $this->attributes)
                ? $this->serializeAttribute($name, $this->attributes[$name])
                : null;
        }

        return $key;
    }

    public function newQuery(): Builder
    {
        return (new Builder($this->getConnection(), $this->getTable()))
            ->setModel(static::class)
            ->setKeyNames($this->getKeyNames());
    }

    public static function query(): Builder
    {
        return (new static)->newQuery();
    }

    public static function scan(): Builder
    {
        return (new static)->newQuery()->scan();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = []): static
    {
        $model = new static;
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * @param  array<string, mixed>|string|int  $key
     */
    public static function find(array|string|int $key, array|string $columns = ['*']): ?static
    {
        $model = (new static)->newQuery()->find($key, $columns);

        return $model instanceof Model ? $model : null;
    }

    /**
     * @param  array<string, mixed>|string|int  $key
     */
    public static function findOrFail(array|string|int $key, array|string $columns = ['*']): static
    {
        $model = static::find($key, $columns);

        if ($model === null) {
            throw new ModelNotFoundException(static::class, $key);
        }

        return $model;
    }

    public static function all(array|string $columns = ['*']): Collection
    {
        return static::query()->select($columns)->get();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (($options['timestamps'] ?? $this->usesTimestamps()) === true) {
            $this->updateTimestamps();
        }

        $this->assertKeysAreSet();

        if ($this->exists) {
            $this->assertKeyHasNotChanged();

            return $this->performUpdate($options);
        }

        return $this->performInsert($options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function performInsert(array $options = []): bool
    {
        $connection = $this->getConnection();

        $params = [
            'TableName' => $connection->tableName($this->getTable()),
            'Item' => $connection->marshalItem($this->getAttributesForDynamoDb()),
        ];

        if (! ($options['overwrite'] ?? false)) {
            [$condition, $names] = $this->keyConditionExpression('attribute_not_exists');
            $params['ConditionExpression'] = $condition;
            $params['ExpressionAttributeNames'] = $names;
        }

        $connection->client()->putItem($params);

        $this->exists = true;
        $this->syncOriginal();

        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function performUpdate(array $options = []): bool
    {
        $connection = $this->getConnection();
        $dirty = $this->getDirty();

        foreach ($this->getKeyNames() as $name) {
            unset($dirty[$name]);
        }

        if ($dirty === []) {
            return true;
        }

        $sets = [];
        $names = [];
        $values = [];

        foreach ($dirty as $attribute => $value) {
            $index = count($sets);
            $namePlaceholder = '#a'.$index;
            $valuePlaceholder = ':v'.$index;

            $sets[] = "{$namePlaceholder} = {$valuePlaceholder}";
            $names[$namePlaceholder] = $attribute;
            $values[$valuePlaceholder] = $connection->marshaler()->marshalValue(
                $this->serializeAttribute($attribute, $value)
            );
        }

        $params = [
            'TableName' => $connection->tableName($this->getTable()),
            'Key' => $connection->marshalItem($this->getKeyValues()),
            'UpdateExpression' => 'SET '.implode(', ', $sets),
            'ExpressionAttributeNames' => $names,
            'ExpressionAttributeValues' => $values,
            'ReturnValues' => 'ALL_NEW',
        ];

        if (! ($options['overwrite'] ?? false)) {
            [$condition, $keyNames] = $this->keyConditionExpression('attribute_exists');
            $params['ConditionExpression'] = $condition;
            $params['ExpressionAttributeNames'] = array_merge($params['ExpressionAttributeNames'], $keyNames);
        }

        $result = $connection->client()->updateItem($params);

        if (isset($result['Attributes'])) {
            $this->setRawAttributes($connection->unmarshalItem($result['Attributes']), true);
        } else {
            $this->syncOriginal();
        }

        return true;
    }

    public function delete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $this->assertKeysAreSet();

        $connection = $this->getConnection();
        [$condition, $names] = $this->keyConditionExpression('attribute_exists');

        $connection->client()->deleteItem([
            'TableName' => $connection->tableName($this->getTable()),
            'Key' => $connection->marshalItem($this->getKeyValues()),
            'ConditionExpression' => $condition,
            'ExpressionAttributeNames' => $names,
        ]);

        $this->exists = false;

        return true;
    }

    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $this->assertKeysAreSet();

        $connection = $this->getConnection();

        $result = $connection->client()->getItem([
            'TableName' => $connection->tableName($this->getTable()),
            'Key' => $connection->marshalItem($this->getKeyValues()),
            'ConsistentRead' => true,
        ]);

        if (! isset($result['Item'])) {
            throw new ModelNotFoundException(static::class, $this->getKeyValues());
        }

        $this->setRawAttributes($connection->unmarshalItem($result['Item']), true);

        return $this;
    }

    public function fresh(): ?static
    {
        foreach ($this->getKeyValues() as $value) {
            if ($value === null || $value === '') {
                return null;
            }
        }

        return static::find($this->getKeyValues());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->fillable, true)) {
            return true;
        }

        if ($this->fillable !== []) {
            return false;
        }

        return ! in_array($key, $this->guarded, true) && ! in_array('*', $this->guarded, true);
    }

    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;

        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }

        if (
            $this->usesTimestamps()
            && $value !== null
            && ! $this->hasCast($key)
            && in_array($key, [$this->createdAt, $this->updatedAt], true)
        ) {
            return $this->asDateTime($value);
        }

        return $this->castAttribute($key, $value);
    }

    public function setAttribute(string $key, mixed $value): static
    {
        if ($this->hasSetMutator($key)) {
            $this->attributes[$key] = $this->mutateAttributeSetter($key, $value);

            return $this;
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Return a cast value ready for the DynamoDB Marshaler.
     */
    public function getAttributeForDynamoDb(string $key): mixed
    {
        return $this->serializeAttribute($key, $this->attributes[$key] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributesForDynamoDb(): array
    {
        $attributes = [];

        foreach ($this->attributes as $key => $value) {
            $attributes[$key] = $this->serializeAttribute($key, $value);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setRawAttributes(array $attributes, bool $sync = false): static
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->syncOriginal();
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOriginal(): array
    {
        return $this->original;
    }

    public function syncOriginal(): static
    {
        $this->original = $this->attributes;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (! array_key_exists($key, $this->original) || ! $this->originalIsEquivalent($key, $value)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(string|array|null $attributes = null): bool
    {
        $dirty = $this->getDirty();

        if ($attributes === null) {
            return $dirty !== [];
        }

        foreach ((array) $attributes as $attribute) {
            if (array_key_exists($attribute, $dirty)) {
                return true;
            }
        }

        return false;
    }

    public function isClean(): bool
    {
        return ! $this->isDirty();
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function usesTimestamps(): bool
    {
        return (bool) $this->timestamps;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = [];

        foreach ($this->attributes as $key => $value) {
            $value = $this->getAttribute($key);

            if ($value instanceof Collection) {
                $value = $value->all();
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options) ?: '{}';
    }

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__unset($offset);
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->newQuery()->{$method}(...$parameters);
    }

    public static function __callStatic(string $method, array $parameters): mixed
    {
        return (new static)->{$method}(...$parameters);
    }

    protected function assertKeysAreSet(): void
    {
        foreach ($this->getKeyValues() as $name => $value) {
            if ($value === null || $value === '') {
                throw new DynamoDbException(
                    'The DynamoDB key ['.$name.'] must be set before saving model ['.static::class.'].'
                );
            }
        }
    }

    protected function assertKeyHasNotChanged(): void
    {
        foreach ($this->getKeyValues() as $name => $value) {
            if (! array_key_exists($name, $this->original)) {
                continue;
            }

            $original = $this->serializeAttribute($name, $this->original[$name]);

            if ($original != $value) {
                throw new DynamoDbException(
                    "The DynamoDB key [{$name}] cannot be changed after an item is saved."
                );
            }
        }
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    protected function keyConditionExpression(string $function): array
    {
        $parts = [];
        $names = [];

        foreach (array_keys($this->getKeyValues()) as $index => $name) {
            $placeholder = '#k'.$index;
            $parts[] = "{$function}({$placeholder})";
            $names[$placeholder] = $name;
        }

        return [implode(' AND ', $parts), $names];
    }

    protected function updateTimestamps(): void
    {
        $time = $this->freshTimestamp();

        if (! $this->exists) {
            if (! array_key_exists($this->createdAt, $this->attributes) || $this->attributes[$this->createdAt] === null) {
                $this->setAttribute($this->createdAt, $time);
            }
        }

        $this->setAttribute($this->updatedAt, $time);
    }

    protected function freshTimestamp(): Carbon
    {
        return Carbon::now();
    }

    protected function originalIsEquivalent(string $key, mixed $value): bool
    {
        $current = $this->serializeAttribute($key, $value);
        $original = $this->serializeAttribute($key, $this->original[$key]);

        if ($current instanceof SetValue && $original instanceof SetValue) {
            return iterator_to_array($current) == iterator_to_array($original);
        }

        return $current == $original;
    }

    protected function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get'.Str::studly($key).'Attribute');
    }

    protected function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set'.Str::studly($key).'Attribute');
    }

    protected function mutateAttribute(string $key, mixed $value): mixed
    {
        return $this->{'get'.Str::studly($key).'Attribute'}($value);
    }

    protected function mutateAttributeSetter(string $key, mixed $value): mixed
    {
        return $this->{'set'.Str::studly($key).'Attribute'}($value);
    }

    protected function hasCast(string $key): bool
    {
        return array_key_exists($key, $this->casts);
    }

    protected function getCastType(string $key): ?string
    {
        if (! $this->hasCast($key)) {
            return null;
        }

        return strtolower(trim(explode(':', (string) $this->casts[$key], 2)[0]));
    }

    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->getCastType($key)) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            'array', 'json' => is_array($value) ? $value : json_decode((string) $value, true),
            'object' => is_array($value) ? (object) $value : json_decode((string) $value),
            'collection' => new Collection(
                is_array($value) ? $value : (json_decode((string) $value, true) ?? [])
            ),
            'date', 'datetime' => $this->asDateTime($value),
            'immutable_date', 'immutable_datetime' => $this->asDateTime($value)->toImmutable(),
            'timestamp' => $this->asDateTime($value)->getTimestamp(),
            'string_set', 'number_set' => $value instanceof SetValue
                ? iterator_to_array($value)
                : (array) $value,
            default => $value,
        };
    }

    protected function serializeAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->getCastType($key)) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            'date' => $this->asDateTime($value)->format('Y-m-d'),
            'datetime', 'immutable_date', 'immutable_datetime' => $this->asDateTime($value)->format('Y-m-d H:i:s'),
            'timestamp' => $this->asDateTime($value)->getTimestamp(),
            'array' => $value,
            'json' => is_string($value) ? $value : json_encode($value),
            'object' => $value,
            'collection' => $value instanceof Collection ? $value->all() : $value,
            'string_set' => new SetValue(array_values(array_map(
                'strval',
                $value instanceof SetValue ? iterator_to_array($value) : (array) $value
            ))),
            'number_set' => new SetValue(array_values(array_map(
                fn (mixed $item): int|float => is_numeric($item) ? $item + 0 : 0,
                $value instanceof SetValue ? iterator_to_array($value) : (array) $value
            ))),
            default => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value,
        };
    }

    protected function asDateTime(mixed $value): Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return Carbon::parse((string) $value);
    }
}
