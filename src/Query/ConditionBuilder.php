<?php

namespace Shaik\LaravelDynamoDb\Query;

use Closure;
use InvalidArgumentException;

/**
 * Builds KeyConditionExpression and FilterExpression strings.
 */
class ConditionBuilder
{
    /** @var array<int, array<string, mixed>> */
    protected array $conditions = [];

    public function __construct(protected ExpressionContext $context)
    {
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /**
     * Add a condition.
     *
     * Supported operators: =, <>, <, <=, >, >=, begins_with, contains, in,
     * between, attribute_exists and attribute_not_exists.
     */
    public function where(
        string|Closure $attribute,
        mixed $operator = null,
        mixed $value = null,
        string $boolean = 'and'
    ): static {
        $boolean = strtolower($boolean) === 'or' ? 'OR' : 'AND';

        if ($attribute instanceof Closure) {
            $nested = new static($this->context);
            $attribute($nested);

            if (! $nested->isEmpty()) {
                $this->conditions[] = [
                    'type' => 'nested',
                    'boolean' => $boolean,
                    'builder' => $nested,
                ];
            }

            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = $this->normalizeOperator((string) $operator);

        if ($value === null && ! in_array($operator, ['attribute_exists', 'attribute_not_exists'], true)) {
            throw new InvalidArgumentException(
                "The value for attribute [{$attribute}] cannot be null. DynamoDB does not store null values."
            );
        }

        if ($operator === 'between' && (! is_array($value) || count($value) !== 2)) {
            throw new InvalidArgumentException('The between operator requires an array with exactly two values.');
        }

        if ($operator === 'in' && (! is_array($value) || $value === [])) {
            throw new InvalidArgumentException('The in operator requires a non-empty array of values.');
        }

        $this->conditions[] = [
            'type' => 'basic',
            'boolean' => $boolean,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function orWhere(string|Closure $attribute, mixed $operator = null, mixed $value = null): static
    {
        if ($attribute instanceof Closure) {
            return $this->where($attribute, null, null, 'or');
        }

        if (func_num_args() === 2) {
            return $this->where($attribute, '=', $operator, 'or');
        }

        return $this->where($attribute, $operator, $value, 'or');
    }

    public function whereIn(string $attribute, array $values): static
    {
        return $this->where($attribute, 'in', $values);
    }

    public function whereBetween(string $attribute, array $values): static
    {
        return $this->where($attribute, 'between', $values);
    }

    public function whereBeginsWith(string $attribute, mixed $value): static
    {
        return $this->where($attribute, 'begins_with', $value);
    }

    public function whereContains(string $attribute, mixed $value): static
    {
        return $this->where($attribute, 'contains', $value);
    }

    public function whereAttributeExists(string $attribute): static
    {
        return $this->where($attribute, 'attribute_exists', null);
    }

    public function whereAttributeNotExists(string $attribute): static
    {
        return $this->where($attribute, 'attribute_not_exists', null);
    }

    public function toExpression(): ?string
    {
        if ($this->conditions === []) {
            return null;
        }

        $parts = [];

        foreach ($this->conditions as $index => $condition) {
            $expression = $this->compile($condition);

            $parts[] = $index === 0
                ? $expression
                : $condition['boolean'].' '.$expression;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    protected function compile(array $condition): string
    {
        if ($condition['type'] === 'nested') {
            /** @var static $builder */
            $builder = $condition['builder'];

            return '('.$builder->toExpression().')';
        }

        $name = $this->context->name($condition['attribute']);
        $operator = $condition['operator'];

        if ($operator === 'attribute_exists') {
            return "attribute_exists({$name})";
        }

        if ($operator === 'attribute_not_exists') {
            return "attribute_not_exists({$name})";
        }

        if ($operator === 'begins_with') {
            return "begins_with({$name}, {$this->context->value($condition['value'])})";
        }

        if ($operator === 'contains') {
            return "contains({$name}, {$this->context->value($condition['value'])})";
        }

        if ($operator === 'between') {
            return sprintf(
                '%s BETWEEN %s AND %s',
                $name,
                $this->context->value($condition['value'][0]),
                $this->context->value($condition['value'][1])
            );
        }

        if ($operator === 'in') {
            $placeholders = array_map(
                fn (mixed $value): string => $this->context->value($value),
                array_values($condition['value'])
            );

            return "{$name} IN (".implode(', ', $placeholders).')';
        }

        return "{$name} {$operator} {$this->context->value($condition['value'])}";
    }

    protected function normalizeOperator(string $operator): string
    {
        $operator = strtolower(trim($operator));

        return match ($operator) {
            '=', '==', 'eq' => '=',
            '<>', '!=', 'ne' => '<>',
            '<', 'lt' => '<',
            '<=', 'lte' => '<=',
            '>', 'gt' => '>',
            '>=', 'gte' => '>=',
            'begins_with', 'beginswith', 'starts_with' => 'begins_with',
            'contains' => 'contains',
            'in' => 'in',
            'between' => 'between',
            'attribute_exists', 'exists' => 'attribute_exists',
            'attribute_not_exists', 'not_exists' => 'attribute_not_exists',
            default => throw new InvalidArgumentException("Unsupported DynamoDB operator [{$operator}]."),
        };
    }
}
