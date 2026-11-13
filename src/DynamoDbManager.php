<?php

namespace Shaik\LaravelDynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Shaik\LaravelDynamoDb\Query\Builder;

/**
 * Resolves and caches DynamoDB connections.
 */
class DynamoDbManager
{
    /** @var array<string, Connection> */
    protected array $connections = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected Container $container, protected array $config = [])
    {
    }

    /**
     * Get a connection instance by name.
     */
    public function connection(?string $name = null): Connection
    {
        $name = $name ?: $this->getDefaultConnection();

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->makeConnection($name);
        }

        return $this->connections[$name];
    }

    public function getDefaultConnection(): string
    {
        return $this->config['default'] ?? 'default';
    }

    public function setDefaultConnection(string $name): void
    {
        $this->config['default'] = $name;
    }

    /**
     * Start a fluent query against a table on the default connection.
     */
    public function table(string $table): Builder
    {
        return $this->connection()->table($table);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getConnections(): array
    {
        return $this->config['connections'] ?? [];
    }

    /**
     * Forget a cached connection, or every cached connection.
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->connections = [];

            return;
        }

        unset($this->connections[$name]);
    }

    protected function makeConnection(string $name): Connection
    {
        $config = $this->configuration($name);

        $clientConfig = [
            'version' => $config['version'] ?? 'latest',
            'region' => $config['region'] ?? 'us-east-1',
        ];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $clientConfig['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];

            if (! empty($config['token'])) {
                $clientConfig['credentials']['token'] = $config['token'];
            }
        }

        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }

        if (! empty($config['http'])) {
            $clientConfig['http'] = $config['http'];
        }

        if (isset($config['retries'])) {
            $clientConfig['retries'] = (int) $config['retries'];
        }

        $client = new DynamoDbClient($clientConfig);
        $marshaler = new Marshaler($config['marshaler'] ?? []);

        return new Connection(
            $client,
            $marshaler,
            (string) ($config['table_prefix'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function configuration(string $name): array
    {
        $connections = $this->config['connections'] ?? [];

        if (! isset($connections[$name])) {
            throw new InvalidArgumentException("DynamoDB connection [{$name}] is not configured.");
        }

        return $connections[$name];
    }

    /**
     * Forward calls to the default connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->{$method}(...$parameters);
    }
}
