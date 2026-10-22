<?php

declare(strict_types=1);

namespace Ethernize\Strata\Dialect;

use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Schema\Column;
use PDO;

/**
 * MySQL and MariaDB.
 */
final class MySqlDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function dsn(ConnectionConfig $config): string
    {
        $dsn = 'mysql:host=' . $config->host;

        if ($config->port !== null) {
            $dsn .= ';port=' . $config->port;
        }

        return $dsn . ';dbname=' . $config->database . ';charset=' . $config->charset;
    }

    public function pdoOptions(ConnectionConfig $config): array
    {
        return [
            PDO::ATTR_TIMEOUT => $config->timeout,
            // Real prepared statements. With them the server also refuses several statements per call.
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    public function afterConnect(PDO $pdo, ConnectionConfig $config): void
    {
        // Strict mode turns silent truncation and invalid dates into errors.
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES')");
    }

    public function quoteIdentifier(string $segment): string
    {
        return $this->quoteWith($segment, '`');
    }

    public function limitOffset(?int $limit, ?int $offset): string
    {
        // MySQL has no OFFSET without LIMIT; this is the documented "all rows" value.
        if ($limit === null && $offset !== null && $offset > 0) {
            return ' LIMIT 18446744073709551615 OFFSET ' . $offset;
        }

        return parent::limitOffset($limit, $offset);
    }

    public function dropIndexSql(string $quotedIndex, string $quotedTable): string
    {
        return 'DROP INDEX ' . $quotedIndex . ' ON ' . $quotedTable;
    }

    public function dropForeignSql(string $quotedTable, string $quotedName): string
    {
        return 'ALTER TABLE ' . $quotedTable . ' DROP FOREIGN KEY ' . $quotedName;
    }

    public function transactionalDdl(): bool
    {
        return false;
    }

    public function backslashEscapes(): bool
    {
        return true;
    }

    public function tableExistsSql(): string
    {
        return 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
    }

    protected function autoIncrementSql(): string
    {
        return 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'string' => 'VARCHAR(' . $column->length . ')',
            'text' => 'TEXT',
            'integer' => 'INT' . ($column->unsigned ? ' UNSIGNED' : ''),
            'bigInteger' => 'BIGINT' . ($column->unsigned ? ' UNSIGNED' : ''),
            'boolean' => 'TINYINT(1)',
            'float' => 'DOUBLE',
            'decimal' => 'DECIMAL(' . $column->length . ', ' . $column->scale . ')',
            'date' => 'DATE',
            'dateTime' => 'DATETIME',
        };
    }
}
