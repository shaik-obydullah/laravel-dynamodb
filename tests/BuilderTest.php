<?php

namespace Shaik\LaravelDynamoDb\Tests;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use Shaik\LaravelDynamoDb\Connection;

class BuilderTest extends TestCase
{
    public function test_it_builds_and_runs_a_query(): void
    {
        $mock = new MockHandler([
            new Result([
                'Items' => [
                    ['id' => ['S' => 'user#1'], 'name' => ['S' => 'Alice']],
                ],
                'Count' => 1,
            ]),
        ]);

        $results = $this->connection($mock)
            ->table('users')
            ->setKeyNames(['id'])
            ->where('id', '=', 'user#1')
            ->filter('status', 'active')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()['name']);

        $command = $mock->getLastCommand();
        $params = $command->toArray();

        $this->assertSame('Query', $command->getName());
        $this->assertSame('users', $params['TableName']);
        $this->assertSame('#n0 = :v0', $params['KeyConditionExpression']);
        $this->assertSame('#n1 = :v1', $params['FilterExpression']);
        $this->assertSame(['#n0' => 'id', '#n1' => 'status'], $params['ExpressionAttributeNames']);
        $this->assertSame([':v0' => ['S' => 'user#1'], ':v1' => ['S' => 'active']], $params['ExpressionAttributeValues']);
    }

    public function test_it_falls_back_to_scan_without_key_conditions(): void
    {
        $mock = new MockHandler([
            new Result(['Items' => [], 'Count' => 0]),
        ]);

        $this->connection($mock)
            ->table('users')
            ->filter('status', 'active')
            ->get();

        $command = $mock->getLastCommand();
        $params = $command->toArray();

        $this->assertSame('Scan', $command->getName());
        $this->assertArrayNotHasKey('KeyConditionExpression', $params);
        $this->assertSame('#n0 = :v0', $params['FilterExpression']);
    }

    public function test_it_applies_index_projection_limit_and_order(): void
    {
        $mock = new MockHandler([
            new Result(['Items' => [], 'Count' => 0]),
        ]);

        $this->connection($mock)
            ->table('users')
            ->setKeyNames(['id'])
            ->useIndex('status-index')
            ->select(['id', 'status'])
            ->where('status', 'active')
            ->limit(10)
            ->descending()
            ->get();

        $params = $mock->getLastCommand()->toArray();

        $this->assertSame('status-index', $params['IndexName']);
        $this->assertSame('#n1, #n0', $params['ProjectionExpression']);
        $this->assertSame(10, $params['Limit']);
        $this->assertFalse($params['ScanIndexForward']);
    }

    public function test_it_counts_without_projecting_attributes(): void
    {
        $mock = new MockHandler([
            new Result(['Count' => 7]),
        ]);

        $count = $this->connection($mock)
            ->table('users')
            ->setKeyNames(['id'])
            ->select(['id', 'name'])
            ->where('id', '=', 'user#1')
            ->count();

        $params = $mock->getLastCommand()->toArray();

        $this->assertSame(7, $count);
        $this->assertSame('COUNT', $params['Select']);
        $this->assertArrayNotHasKey('ProjectionExpression', $params);
        $this->assertSame(['#n0' => 'id'], $params['ExpressionAttributeNames']);
    }

    public function test_it_exposes_the_last_evaluated_key(): void
    {
        $mock = new MockHandler([
            new Result([
                'Items' => [],
                'Count' => 0,
                'LastEvaluatedKey' => ['id' => ['S' => 'user#1']],
            ]),
        ]);

        $builder = $this->connection($mock)
            ->table('users')
            ->setKeyNames(['id'])
            ->where('id', '=', 'user#1');

        $builder->get();

        $this->assertTrue($builder->hasMorePages());
        $this->assertSame(['id' => 'user#1'], $builder->getLastEvaluatedKey());
    }

    public function test_it_can_find_by_primary_key(): void
    {
        $mock = new MockHandler([
            new Result([
                'Items' => [
                    ['id' => ['S' => 'user#1'], 'name' => ['S' => 'Alice']],
                ],
                'Count' => 1,
            ]),
        ]);

        $result = $this->connection($mock)
            ->table('users')
            ->setKeyNames(['id'])
            ->find('user#1');

        $this->assertSame('Alice', $result['name']);
        $this->assertSame('#n0 = :v0', $mock->getLastCommand()->toArray()['KeyConditionExpression']);
    }

    protected function connection(MockHandler $mock): Connection
    {
        $client = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'handler' => $mock,
            'credentials' => [
                'key' => 'test',
                'secret' => 'test',
            ],
        ]);

        return new Connection($client, new Marshaler());
    }
}
