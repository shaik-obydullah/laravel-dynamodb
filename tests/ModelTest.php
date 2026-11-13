<?php

namespace Shaik\LaravelDynamoDb\Tests;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use Shaik\LaravelDynamoDb\Connection;
use Shaik\LaravelDynamoDb\Tests\Fixtures\TimestampedUser;
use Shaik\LaravelDynamoDb\Tests\Fixtures\User;

class ModelTest extends TestCase
{
    protected MockHandler $mock;

    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock = new MockHandler();
        $this->connection = new Connection(
            new DynamoDbClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'handler' => $this->mock,
                'credentials' => ['key' => 'test', 'secret' => 'test'],
            ]),
            new Marshaler()
        );

        User::setConnectionResolver(fn (): Connection => $this->connection);
        TimestampedUser::setConnectionResolver(fn (): Connection => $this->connection);
    }

    protected function tearDown(): void
    {
        User::setConnectionResolver(null);
        TimestampedUser::setConnectionResolver(null);

        parent::tearDown();
    }

    public function test_it_inserts_an_item_with_casts(): void
    {
        $this->mock->append(new Result([]));

        $user = new User([
            'id' => 'user#1',
            'name' => 'Alice',
            'active' => 1,
            'age' => '31',
            'tags' => ['admin', 'editor'],
        ]);

        $this->assertTrue($user->save());
        $this->assertTrue($user->exists());

        $command = $this->mock->getLastCommand();
        $params = $command->toArray();

        $this->assertSame('PutItem', $command->getName());
        $this->assertSame('users', $params['TableName']);
        $this->assertSame(['S' => 'user#1'], $params['Item']['id']);
        $this->assertSame(['S' => 'Alice'], $params['Item']['name']);
        $this->assertSame(['BOOL' => true], $params['Item']['active']);
        $this->assertSame(['N' => '31'], $params['Item']['age']);
        $this->assertSame(['L' => [['S' => 'admin'], ['S' => 'editor']]], $params['Item']['tags']);
        $this->assertSame('attribute_not_exists(#k0)', $params['ConditionExpression']);
        $this->assertSame(['#k0' => 'id'], $params['ExpressionAttributeNames']);
    }

    public function test_it_updates_dirty_attributes_only(): void
    {
        $this->mock->append(new Result([
            'Attributes' => [
                'id' => ['S' => 'user#1'],
                'name' => ['S' => 'Bob'],
                'age' => ['N' => '31'],
            ],
        ]));

        $user = User::newFromDynamoDb([
            'id' => 'user#1',
            'name' => 'Alice',
            'age' => 31,
        ]);

        $user->name = 'Bob';

        $this->assertTrue($user->save());
        $this->assertSame('Bob', $user->name);
        $this->assertFalse($user->isDirty());

        $command = $this->mock->getLastCommand();
        $params = $command->toArray();

        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame('SET #a0 = :v0', $params['UpdateExpression']);
        $this->assertSame(['#a0' => 'name', '#k0' => 'id'], $params['ExpressionAttributeNames']);
        $this->assertSame([':v0' => ['S' => 'Bob']], $params['ExpressionAttributeValues']);
        $this->assertSame('attribute_exists(#k0)', $params['ConditionExpression']);
    }

    public function test_it_finds_and_deletes_a_model(): void
    {
        $this->mock->append(new Result([
            'Items' => [
                ['id' => ['S' => 'user#1'], 'name' => ['S' => 'Alice'], 'active' => ['BOOL' => true]],
            ],
            'Count' => 1,
        ]));

        $user = User::find('user#1');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
        $this->assertTrue($user->active);

        $this->mock->append(new Result([]));

        $this->assertTrue($user->delete());
        $this->assertFalse($user->exists());
        $this->assertSame('DeleteItem', $this->mock->getLastCommand()->getName());
    }

    public function test_it_sets_timestamps_on_new_models(): void
    {
        $this->mock->append(new Result([]));

        $user = new TimestampedUser(['id' => 'user#1', 'name' => 'Alice']);
        $user->save();

        $item = $this->mock->getLastCommand()->toArray()['Item'];

        $this->assertArrayHasKey('created_at', $item);
        $this->assertArrayHasKey('updated_at', $item);
    }

    public function test_it_refuses_to_change_a_key_on_an_existing_model(): void
    {
        $user = User::newFromDynamoDb(['id' => 'user#1', 'name' => 'Alice']);
        $user->id = 'user#2';

        $this->expectException(\Shaik\LaravelDynamoDb\Exceptions\DynamoDbException::class);

        $user->save();
    }

    public function test_it_hydrates_attributes_and_casts(): void
    {
        $user = User::newFromDynamoDb([
            'id' => 'user#1',
            'active' => true,
            'age' => 31,
            'tags' => ['admin'],
            'joined_at' => '2026-01-02 03:04:05',
        ]);

        $this->assertTrue($user->active);
        $this->assertSame(31, $user->age);
        $this->assertSame(['admin'], $user->tags);
        $this->assertSame('2026-01-02 03:04:05', $user->joined_at->format('Y-m-d H:i:s'));
    }
}
