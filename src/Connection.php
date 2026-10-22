<?php

declare(strict_types=1);

namespace Ethernize\Strata;

use Closure;
use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Dialect\Dialect;
use Ethernize\Strata\Exception\ConnectionException;
use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Exception\QueryException;
use Ethernize\Strata\Exception\TransactionException;
use Ethernize\Strata\Query\CompiledQuery;
use Ethernize\Strata\Query\QueryBuilder;
use Ethernize\Strata\Support\SqlGuard;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * One named database connection.
 *
 * The PDO connection is opened the first time something needs it and can be
 * closed with disconnect(); using the object afterwards opens a new one. Nothing
 * here is persistent across requests: PDO::ATTR_PERSISTENT is never set.
 *
 * Values always travel as bound parameters. The two "Raw" methods accept SQL
 * written by the developer; the query builder never does.
 */
final class Connection
{
    private ?PDO $pdo = null;
    private int $level = 0;

    /**
     * @param (Closure(string, ?string, ?string, array<int, mixed>): PDO)|null $pdoFactory replaces `new PDO(...)`, for tests
     */
    public function __construct(
        private readonly ConnectionConfig $config,
        private readonly Dialect $dialect,
        private readonly ?Closure $pdoFactory = null,
    ) {
    }

    public function name(): string
    {
        return $this->config->name;
    }

    public function driver(): string
    {
        return $this->dialect->name();
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    /**
     * The version string of the database server (opens the connection).
     */
    public function serverVersion(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * The underlying PDO, opening the connection if needed. Prefer the methods of this class:
     * statements run directly on PDO bypass the error translation and the checks.
     */
    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * Closes the connection. An open transaction is rolled back, never committed.
     */
    public function disconnect(): void
    {
        if ($this->pdo !== null && $this->level > 0) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // The server drops the transaction when the connection closes anyway.
            }
        }

        $this->pdo = null;
        $this->level = 0;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    // Raw SQL (the explicit escape hatch)

    /**
     * Runs SQL written by the developer and returns all rows. Values must be passed in $bindings,
     * positional (?) or named (:name), never concatenated into the text.
     *
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function selectRaw(string $sql, array $bindings = []): array
    {
        SqlGuard::assertValid($sql, $bindings, $this->dialect->backslashEscapes());

        return $this->fetchAll(new CompiledQuery($sql, $bindings));
    }

    /**
     * Runs SQL written by the developer and returns the number of affected rows.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function executeRaw(string $sql, array $bindings = []): int
    {
        SqlGuard::assertValid($sql, $bindings, $this->dialect->backslashEscapes());

        return $this->execute(new CompiledQuery($sql, $bindings));
    }

    /**
     * Quotes a string for use as a SQL literal. Only for places that cannot take parameters (DDL defaults).
     */
    public function quoteLiteral(string $value): string
    {
        $quoted = $this->pdo()->quote($value);

        if ($quoted === false) {
            throw new InvalidQueryException('The driver cannot quote this value.');
        }

        return $quoted;
    }

    // Execution of compiled (builder) queries

    /**
     * @internal used by QueryBuilder
     * @return list<array<string, mixed>>
     */
    public function fetchAll(CompiledQuery $query): array
    {
        $statement = $this->run($query);

        try {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw $this->queryException($query->sql, $exception);
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * @internal used by QueryBuilder
     */
    public function execute(CompiledQuery $query): int
    {
        $statement = $this->run($query);
        $count = $statement->rowCount();
        $statement->closeCursor();

        return $count;
    }

    /**
     * @internal used by QueryBuilder
     */
    public function lastInsertId(): int|string
    {
        $id = $this->pdo()->lastInsertId();

        return ctype_digit((string) $id) && strlen((string) $id) < 19 ? (int) $id : $id;
    }

    // Transactions

    public function transactionLevel(): int
    {
        return $this->level;
    }

    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    /**
     * Starts a transaction, or a savepoint when one is already open.
     */
    public function beginTransaction(): void
    {
        $pdo = $this->pdo();

        try {
            if ($this->level === 0) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $this->savepoint($this->level));
            }
        } catch (PDOException $exception) {
            throw new TransactionException('Could not start the transaction.', 0, $this->scrub($exception));
        }

        $this->level++;
    }

    /**
     * Commits the outermost transaction, or releases the innermost savepoint.
     */
    public function commit(): void
    {
        if ($this->level === 0) {
            throw new TransactionException('There is no open transaction to commit.');
        }

        $pdo = $this->pdo();

        try {
            if ($this->level === 1) {
                if (!$pdo->inTransaction()) {
                    $this->level = 0;

                    throw new TransactionException('The transaction was ended by the database before commit (DDL statements can do this on MySQL). Nothing more was committed.');
                }

                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint($this->level - 1));
            }
        } catch (PDOException $exception) {
            throw new TransactionException('The commit failed.', 0, $this->scrub($exception));
        }

        $this->level--;
    }

    /**
     * Rolls back the outermost transaction, or back to the innermost savepoint.
     */
    public function rollBack(): void
    {
        if ($this->level === 0) {
            throw new TransactionException('There is no open transaction to roll back.');
        }

        $pdo = $this->pdo();

        try {
            if ($this->level === 1) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $name = $this->savepoint($this->level - 1);
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            }
        } catch (PDOException $exception) {
            // The state of the transaction is unknown: close the connection so nothing can commit it.
            $this->disconnect();

            throw new TransactionException('The rollback failed; the connection was closed.', 0, $this->scrub($exception));
        }

        $this->level--;
    }

    /**
     * Runs $callback in a transaction (a savepoint when nested). If it throws, everything it did is
     * rolled back and the same exception is rethrown. If it returns, the work is committed and the
     * return value is passed on. Nothing is retried.
     *
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();
        $expected = $this->level;

        try {
            $result = $callback($this);

            if ($this->level !== $expected) {
                throw new TransactionException('The transaction level changed inside the callback. Do not call commit() or rollBack() there.');
            }
        } catch (Throwable $exception) {
            $this->abort($expected, $exception);

            throw $exception;
        }

        try {
            $this->commit();
        } catch (Throwable $exception) {
            if ($this->level >= $expected) {
                $this->abort($expected, $exception);
            }

            throw $exception;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->config->name, 'driver' => $this->dialect->name(), 'connected' => $this->pdo !== null, 'transactionLevel' => $this->level];
    }

    private function connect(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
        ] + $this->dialect->pdoOptions($this->config);

        try {
            $pdo = $this->pdoFactory !== null
                ? ($this->pdoFactory)($this->dialect->dsn($this->config), $this->config->username, $this->config->password(), $options)
                : new PDO($this->dialect->dsn($this->config), $this->config->username, $this->config->password(), $options);
            $this->dialect->afterConnect($pdo, $this->config);
        } catch (PDOException $exception) {
            $code = $exception->errorInfo[1] ?? null;

            throw new ConnectionException($this->config->name, is_int($code) ? $code : null);
        }

        return $pdo;
    }

    private function run(CompiledQuery $query): PDOStatement
    {
        $pdo = $this->pdo();

        try {
            $statement = $pdo->prepare($query->sql);
            $this->bind($statement, $query->bindings);
            $statement->execute();
        } catch (PDOException $exception) {
            throw $this->queryException($query->sql, $exception);
        }

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function bind(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');

            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                is_string($value) => PDO::PARAM_STR,
                is_float($value) && is_finite($value) => PDO::PARAM_STR,
                default => throw new InvalidQueryException(sprintf('Cannot bind a value of type %s. Use null, bool, int, float or string.', get_debug_type($value))),
            };

            $statement->bindValue($parameter, is_float($value) ? (string) $value : $value, $type);
        }
    }

    private function queryException(string $sql, PDOException $exception): QueryException
    {
        $info = $exception->errorInfo ?? [];
        $state = is_string($info[0] ?? null) ? $info[0] : (is_string($exception->getCode()) ? $exception->getCode() : null);

        return new QueryException($sql, $state, is_int($info[1] ?? null) ? $info[1] : null, $exception->getMessage());
    }

    /**
     * A PDOException may quote row values; keep only its class and code in the chain.
     */
    private function scrub(PDOException $exception): \RuntimeException
    {
        $state = is_array($exception->errorInfo) && is_string($exception->errorInfo[0] ?? null) ? $exception->errorInfo[0] : 'unknown';

        return new \RuntimeException('Database error (SQLSTATE ' . $state . ').');
    }

    /**
     * Rolls back to the level before $level, keeping $original as the error the caller will see.
     */
    private function abort(int $level, Throwable $original): void
    {
        try {
            while ($this->level >= $level && $this->level > 0) {
                $this->rollBack();
            }
        } catch (Throwable $failure) {
            throw new TransactionException('The rollback failed after an error: ' . $original::class . '.', 0, $failure);
        }
    }

    private function savepoint(int $depth): string
    {
        return 'strata_sp_' . $depth;
    }
}
