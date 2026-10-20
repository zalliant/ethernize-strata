<?php

declare(strict_types=1);

namespace Ethernize\Strata\Dialect;

use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Schema\Column;
use PDO;

abstract class AbstractDialect implements Dialect
{
    public function pdoOptions(ConnectionConfig $config): array
    {
        return [];
    }

    public function afterConnect(PDO $pdo, ConnectionConfig $config): void
    {
    }

    public function limitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        if ($offset !== null && $offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    public function backslashEscapes(): bool
    {
        return false;
    }

    public function usesReturning(): bool
    {
        return false;
    }

    public function columnSql(Column $column, string $literal): string
    {
        if ($column->autoIncrement) {
            return $this->autoIncrementSql();
        }

        $sql = $this->typeSql($column);
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $literal;
        }

        return $sql;
    }

    abstract protected function autoIncrementSql(): string;

    abstract protected function typeSql(Column $column): string;

    /**
     * Doubles the quote character, the only escape every engine agrees on.
     */
    protected function quoteWith(string $segment, string $quote): string
    {
        return $quote . str_replace($quote, $quote . $quote, $segment) . $quote;
    }
}
