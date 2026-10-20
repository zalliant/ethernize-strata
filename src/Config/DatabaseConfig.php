<?php

declare(strict_types=1);

namespace Ethernize\Strata\Config;

use Ethernize\Strata\Exception\ConfigurationException;

/**
 * The "database" configuration section: which connections exist and how to
 * reach them, validated without ever touching the network or the environment.
 *
 *   'default'     => 'main',
 *   'migrations'  => 'database/migrations',
 *   'connections' => [
 *       'main' => ['driver' => 'sqlite', 'database' => 'var/database.sqlite'],
 *       'shop' => [
 *           'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'shop',
 *           'username_env' => 'SHOP_DB_USER', 'password_env' => 'SHOP_DB_PASSWORD',
 *       ],
 *   ],
 *
 * Any field can be given as "<field>_env" instead, naming an environment
 * variable that is read when the connection is opened. The Manifest freezes the
 * configuration into a file that can be distributed, so a literal password is
 * refused here: put it in the environment and name the variable.
 */
final class DatabaseConfig
{
    public const DRIVERS = ['sqlite', 'mysql', 'mariadb', 'pgsql'];

    private const FIELDS = ['driver', 'database', 'host', 'port', 'username', 'password', 'charset', 'timeout', 'schema'];

    /**
     * @param array<string, array<string, mixed>> $connections
     */
    private function __construct(
        private readonly string $default,
        private readonly array $connections,
        private readonly string $migrations,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $connections = $config['connections'] ?? [];

        if (!is_array($connections)) {
            throw new ConfigurationException('database.connections must be an array.');
        }

        foreach ($connections as $name => $settings) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name) !== 1) {
                throw new ConfigurationException('Connection names must be lowercase letters, digits, "_" or "-", starting with a letter.');
            }

            if (!is_array($settings)) {
                throw new ConfigurationException(sprintf('Connection "%s" must be an array.', $name));
            }

            self::validateConnection($name, $settings);
        }

        $default = $config['default'] ?? array_key_first($connections) ?? 'main';

        if (!is_string($default) || ($connections !== [] && !isset($connections[$default]))) {
            throw new ConfigurationException('database.default must name a configured connection.');
        }

        $migrations = $config['migrations'] ?? 'database/migrations';

        if (!is_string($migrations) || $migrations === '' || str_contains($migrations, "\0") || str_contains($migrations, '..')) {
            throw new ConfigurationException('database.migrations must be a relative directory inside the application.');
        }

        return new self($default, $connections, $migrations);
    }

    public function defaultName(): string
    {
        return $this->default;
    }

    public function migrationsPath(): string
    {
        return $this->migrations;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->connections));
    }

    public function has(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * @return array<string, mixed>
     * @throws ConfigurationException
     */
    public function connection(string $name): array
    {
        return $this->connections[$name] ?? throw new ConfigurationException(sprintf(
            'Unknown database connection "%s". Configured: %s.',
            $name,
            $this->connections === [] ? 'none' : implode(', ', array_keys($this->connections)),
        ));
    }

    /**
     * Name and driver of each connection, safe to store in the Manifest.
     *
     * @return array<string, string>
     */
    public function summary(): array
    {
        return array_map(static fn (array $settings): string => (string) ($settings['driver'] ?? ''), $this->connections);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function validateConnection(string $name, array $settings): void
    {
        $driver = $settings['driver'] ?? $settings['driver_env'] ?? null;

        if (!isset($settings['driver']) && !isset($settings['driver_env'])) {
            throw new ConfigurationException(sprintf('Connection "%s" needs a driver (%s).', $name, implode(', ', self::DRIVERS)));
        }

        if (isset($settings['driver']) && !in_array($settings['driver'], self::DRIVERS, true)) {
            throw new ConfigurationException(sprintf('Connection "%s" uses an unsupported driver. Use one of: %s.', $name, implode(', ', self::DRIVERS)));
        }

        foreach ($settings as $key => $value) {
            $field = is_string($key) && str_ends_with($key, '_env') ? substr($key, 0, -4) : $key;

            if (!is_string($key) || !in_array($field, self::FIELDS, true)) {
                throw new ConfigurationException(sprintf('Connection "%s" has an unknown setting "%s".', $name, (string) $key));
            }

            if (str_ends_with($key, '_env')) {
                if (!is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
                    throw new ConfigurationException(sprintf('Connection "%s": "%s" must name an environment variable.', $name, $key));
                }

                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                throw new ConfigurationException(sprintf('Connection "%s": "%s" must be a scalar.', $name, $key));
            }

            if (isset($settings[$key . '_env'])) {
                throw new ConfigurationException(sprintf('Connection "%s" sets both "%s" and "%s_env".', $name, $key, $key));
            }
        }

        if (isset($settings['password']) && $settings['password'] !== '') {
            throw new ConfigurationException(sprintf(
                'Connection "%s" has a literal password. The Manifest would write it to a file that can be distributed. Use "password_env" with the name of an environment variable.',
                $name,
            ));
        }

        unset($driver);
    }
}
