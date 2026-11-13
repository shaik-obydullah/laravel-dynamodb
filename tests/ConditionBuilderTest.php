<?php

namespace Shaik\LaravelDynamoDb\Tests;

use Aws\DynamoDb\Marshaler;
use PHPUnit\Framework\TestCase;
use Shaik\LaravelDynamoDb\Query\ConditionBuilder;
use Shaik\LaravelDynamoDb\Query\ExpressionContext;

class ConditionBuilderTest extends TestCase
{
    public function test_it_builds_basic_conditions_and_values(): void
    {
        $context = new ExpressionContext(new Marshaler());
        $conditions = new ConditionBuilder($context);

        $conditions
            ->where('status', '=', 'active')
            ->orWhere('name', 'begins_with', 'A')
            ->whereIn('role', ['admin', 'editor'])
            ->whereBetween('age', [18, 65]);

        $this->assertSame(
            '#n0 = :v0 OR begins_with(#n1, :v1) AND #n2 IN (:v2, :v3) AND #n3 BETWEEN :v4 AND :v5',
            $conditions->toExpression()
        );

        $this->assertSame([
            ':v0' => ['S' => 'active'],
            ':v1' => ['S' => 'A'],
            ':v2' => ['S' => 'admin'],
            ':v3' => ['S' => 'editor'],
            ':v4' => ['N' => '18'],
            ':v5' => ['N' => '65'],
        ], $context->values());
    }

    public function test_it_builds_nested_groups(): void
    {
        $context = new ExpressionContext(new Marshaler());
        $conditions = new ConditionBuilder($context);

        $conditions
            ->where('tenant_id', 'tenant#1')
            ->where(function (ConditionBuilder $query): void {
                $query->where('status', 'active')->orWhere('status', 'pending');
            });

        $this->assertSame(
            '#n0 = :v0 AND (#n1 = :v1 OR #n1 = :v2)',
            $conditions->toExpression()
        );
    }

    public function test_it_builds_existence_conditions(): void
    {
        $context = new ExpressionContext(new Marshaler());
        $conditions = new ConditionBuilder($context);

        $conditions->whereAttributeExists('deleted_at');
        $conditions->orWhere('deleted_at', 'attribute_not_exists', null);

        $this->assertSame(
            'attribute_exists(#n0) OR attribute_not_exists(#n0)',
            $conditions->toExpression()
        );
    }
}
