<?php

declare(strict_types=1);

namespace Ethernize\Strata\Dialect;

use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Schema\Column;
use PDO;

/**
 * Everything that differs between database engines, in one place: how to
 * connect, how to quote, how to page, how to describe columns.
 */
interface Dialect
{
    public function name(): string;

    public function dsn(ConnectionConfig $config): string;

    /**
     * Extra PDO options for this driver (merged over the common ones).
     *
     * @return array<int, mixed>
     */
    public function pdoOptions(ConnectionConfig $config): array;

    /**
     * Runs once right after connecting (session settings).
     */
    public function afterConnect(PDO $pdo, ConnectionConfig $config): void;

    /**
     * Quotes one already validated identifier segment.
     */
    public function quoteIdentifier(string $segment): string;

    /**
     * LIMIT/OFFSET clause with a leading space, or an empty string.
     */
    public function limitOffset(?int $limit, ?int $offset): string;

    /**
     * Whether DDL statements can be rolled back with the transaction.
     */
    public function transactionalDdl(): bool;

    /**
     * Whether a backslash escapes the next character inside string literals.
     */
    public function backslashEscapes(): bool;

    /**
     * Whether new ids are read with INSERT ... RETURNING instead of lastInsertId().
     */
    public function usesReturning(): bool;

    /**
     * Column definition SQL (without the name).
     */
    public function columnSql(Column $column, string $literal): string;

    /**
     * SQL that returns one row when the named table exists. One bound parameter: the table name.
     */
    public function tableExistsSql(): string;
}
