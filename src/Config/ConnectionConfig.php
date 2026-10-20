<?php

declare(strict_types=1);

namespace Ethernize\Strata\Config;

use Closure;
use Ethernize\Strata\Exception\ConfigurationException;

/**
 * One connection with every "_env" setting resolved. It lives only in memory:
 * it is built when a connection is about to be opened and is never exported.
 * Its string and debug forms hide the secret values.
 */
final class ConnectionConfig
{
    public readonly string $name;
    public readonly string $driver;
    public readonly string $database;
    public readonly ?string $host;
    public readonly ?int $port;
    public readonly ?string $username;
    public readonly ?string $charset;
    public readonly ?string $schema;
    public readonly int $timeout;
    private readonly ?string $password;

    /**
     * @param array<string, mixed>        $settings the raw connection settings
     * @param Closure(string): ?string    $env      reads an environment variable
     * @throws ConfigurationException
     */
    public function __construct(string $name, array $settings, Closure $env, string $basePath)
    {
        $value = static function (string $field) use ($settings, $env, $name): mixed {
            if (isset($settings[$field . '_env'])) {
                $variable = (string) $settings[$field . '_env'];
                $resolved = $env($variable);

                if ($resolved === null || $resolved === '') {
                    if ($field === 'password') {
                        return null;
                    }

                    throw new ConfigurationException(sprintf('Connection "%s": environment variable %s is not set.', $name, $variable));
                }

                return $resolved;
            }

            return $settings[$field] ?? null;
        };

        $driver = (string) $value('driver');
        $driver = $driver === 'mariadb' ? 'mysql' : $driver;

        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new ConfigurationException(sprintf('Connection "%s" uses an unsupported driver.', $name));
        }

        $this->name = $name;
        $this->driver = $driver;
        $database = $value('database');

        if (!is_string($database) || $database === '' || str_contains($database, "\0")) {
            throw new ConfigurationException(sprintf('Connection "%s" needs a database.', $name));
        }

        if ($driver === 'sqlite') {
            $this->database = $database === ':memory:' || self::isAbsolute($database) ? $database : rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $database;
            $this->host = $this->port = $this->username = $this->password = $this->charset = $this->schema = null;
            $this->timeout = max(1, (int) ($value('timeout') ?? 5));

            return;
        }

        if (preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $database) !== 1) {
            throw new ConfigurationException(sprintf('Connection "%s": the database name has invalid characters.', $name));
        }

        $host = (string) ($value('host') ?? '127.0.0.1');

        if (preg_match('/^[A-Za-z0-9._:\[\]-]{1,255}$/', $host) !== 1) {
            throw new ConfigurationException(sprintf('Connection "%s": the host has invalid characters.', $name));
        }

        $port = $value('port');

        if ($port !== null && (!is_numeric($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new ConfigurationException(sprintf('Connection "%s": the port must be between 1 and 65535.', $name));
        }

        $charset = (string) ($value('charset') ?? ($driver === 'mysql' ? 'utf8mb4' : 'UTF8'));

        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $charset) !== 1) {
            throw new ConfigurationException(sprintf('Connection "%s": the charset has invalid characters.', $name));
        }

        $schema = $value('schema');

        if ($schema !== null && (!is_string($schema) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $schema) !== 1)) {
            throw new ConfigurationException(sprintf('Connection "%s": the schema has invalid characters.', $name));
        }

        $username = $value('username');
        $password = $value('password');

        $this->database = $database;
        $this->host = $host;
        $this->port = $port === null ? null : (int) $port;
        $this->username = $username === null ? null : (string) $username;
        $this->password = $password === null ? null : (string) $password;
        $this->charset = $charset;
        $this->schema = $schema === null ? null : (string) $schema;
        $this->timeout = max(1, (int) ($value('timeout') ?? 5));
    }

    public function password(): ?string
    {
        return $this->password;
    }

    /**
     * What is safe to show: everything except the password.
     *
     * @return array<string, scalar|null>
     */
    public function toSafeArray(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver,
            'database' => $this->driver === 'sqlite' ? basename($this->database) : $this->database,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password === null ? null : '********',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toSafeArray();
    }

    public function __serialize(): array
    {
        throw new \LogicException('A connection configuration holds secrets and cannot be serialized.');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
