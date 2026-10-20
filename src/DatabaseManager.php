<?php

declare(strict_types=1);

namespace Ethernize\Strata;

use Closure;
use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Config\DatabaseConfig;
use Ethernize\Strata\Dialect\Dialect;
use Ethernize\Strata\Dialect\MySqlDialect;
use Ethernize\Strata\Dialect\PostgresDialect;
use Ethernize\Strata\Dialect\SqliteDialect;
use Ethernize\Strata\Exception\ConfigurationException;

/**
 * The named connections of one request (or one process in the CLI).
 *
 * Creating a Connection here does not open it: the PDO connection is opened by
 * the first query. The service provider registers this class with request
 * scope and closes everything when the request ends.
 */
final class DatabaseManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /**
     * @param Closure(string): ?string $env reads an environment variable at the moment a connection is created
     * @param (Closure(string, ?string, ?string, array<int, mixed>): \PDO)|null $pdoFactory replaces `new PDO(...)`, for tests
     */
    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly Closure $env,
        private readonly string $basePath,
        private readonly ?Closure $pdoFactory = null,
    ) {
    }

    public function config(): DatabaseConfig
    {
        return $this->config;
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->config->defaultName();

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $resolved = new ConnectionConfig($name, $this->config->connection($name), $this->env, $this->basePath);

        return $this->connections[$name] = new Connection($resolved, self::dialectFor($resolved->driver), $this->pdoFactory);
    }

    /**
     * Names of the connections that were created in this scope.
     *
     * @return list<string>
     */
    public function created(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Names of the connections that currently hold an open PDO connection.
     *
     * @return list<string>
     */
    public function connected(): array
    {
        return array_keys(array_filter($this->connections, static fn (Connection $connection): bool => $connection->isConnected()));
    }

    public function disconnect(?string $name = null): void
    {
        ($this->connections[$name ?? $this->config->defaultName()] ?? null)?->disconnect();
    }

    /**
     * Closes every connection, rolling back open transactions. The Connection objects stay
     * valid and reconnect on next use.
     */
    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
    }

    public static function dialectFor(string $driver): Dialect
    {
        return match ($driver) {
            'sqlite' => new SqliteDialect(),
            'mysql', 'mariadb' => new MySqlDialect(),
            'pgsql' => new PostgresDialect(),
            default => throw new ConfigurationException('Unsupported database driver.'),
        };
    }
}
