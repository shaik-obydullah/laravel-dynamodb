<?php

namespace Shaik\LaravelDynamoDb\Query;

use Aws\DynamoDb\Marshaler;

/**
 * Allocates unique expression attribute names and values for one request.
 *
 * DynamoDB expressions use placeholders (#name and :value) instead of raw
 * attribute names. Key conditions and filter conditions share this context so
 * their placeholders never collide.
 */
class ExpressionContext
{
    /** @var array<string, string> Placeholder => attribute name. */
    protected array $names = [];

    /** @var array<string, string> Attribute name => placeholder. */
    protected array $nameLookup = [];

    /** @var array<string, array> */
    protected array $values = [];

    protected int $nameCounter = 0;

    protected int $valueCounter = 0;

    public function __construct(protected Marshaler $marshaler)
    {
    }

    public function reset(): void
    {
        $this->names = [];
        $this->nameLookup = [];
        $this->values = [];
        $this->nameCounter = 0;
        $this->valueCounter = 0;
    }

    public function name(string $attribute): string
    {
        if (! array_key_exists($attribute, $this->nameLookup)) {
            $placeholder = '#n'.$this->nameCounter++;

            $this->nameLookup[$attribute] = $placeholder;
            $this->names[$placeholder] = $attribute;
        }

        return $this->nameLookup[$attribute];
    }

    public function value(mixed $value): string
    {
        $placeholder = ':v'.$this->valueCounter++;
        $this->values[$placeholder] = $this->marshaler->marshalValue($value);

        return $placeholder;
    }

    /**
     * @return array<string, string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * @return array<string, array>
     */
    public function values(): array
    {
        return $this->values;
    }
}
