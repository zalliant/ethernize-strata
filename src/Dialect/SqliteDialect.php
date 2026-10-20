<?php

declare(strict_types=1);

namespace Ethernize\Strata\Dialect;

use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Schema\Column;
use PDO;

final class SqliteDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function dsn(ConnectionConfig $config): string
    {
        return 'sqlite:' . $config->database;
    }

    public function pdoOptions(ConnectionConfig $config): array
    {
        return [PDO::ATTR_TIMEOUT => $config->timeout];
    }

    public function afterConnect(PDO $pdo, ConnectionConfig $config): void
    {
        // SQLite ignores foreign keys unless asked, and waits for locks instead of failing at once.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . ($config->timeout * 1000));
    }

    public function quoteIdentifier(string $segment): string
    {
        return $this->quoteWith($segment, '"');
    }

    public function limitOffset(?int $limit, ?int $offset): string
    {
        // SQLite has no OFFSET without LIMIT; -1 means no limit.
        if ($limit === null && $offset !== null && $offset > 0) {
            return ' LIMIT -1 OFFSET ' . $offset;
        }

        return parent::limitOffset($limit, $offset);
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function tableExistsSql(): string
    {
        return "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?";
    }

    protected function autoIncrementSql(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'string' => 'VARCHAR(' . $column->length . ')',
            'text' => 'TEXT',
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'boolean' => 'INTEGER',
            'float' => 'REAL',
            'dateTime' => 'DATETIME',
        };
    }
}
