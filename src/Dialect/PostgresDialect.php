<?php

declare(strict_types=1);

namespace Ethernize\Strata\Dialect;

use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Schema\Column;
use PDO;

final class PostgresDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function dsn(ConnectionConfig $config): string
    {
        $dsn = 'pgsql:host=' . $config->host;

        if ($config->port !== null) {
            $dsn .= ';port=' . $config->port;
        }

        return $dsn . ';dbname=' . $config->database . ';connect_timeout=' . $config->timeout . ";options='--client_encoding=" . $config->charset . "'";
    }

    public function pdoOptions(ConnectionConfig $config): array
    {
        return [PDO::ATTR_EMULATE_PREPARES => false];
    }

    public function afterConnect(PDO $pdo, ConnectionConfig $config): void
    {
        if ($config->schema !== null) {
            $pdo->exec('SET search_path TO ' . $this->quoteIdentifier($config->schema));
        }
    }

    public function quoteIdentifier(string $segment): string
    {
        return $this->quoteWith($segment, '"');
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function usesReturning(): bool
    {
        return true;
    }

    public function tableExistsSql(): string
    {
        return 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?';
    }

    protected function autoIncrementSql(): string
    {
        return 'BIGSERIAL PRIMARY KEY';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'string' => 'VARCHAR(' . $column->length . ')',
            'text' => 'TEXT',
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'float' => 'DOUBLE PRECISION',
            'dateTime' => 'TIMESTAMP',
        };
    }
}
