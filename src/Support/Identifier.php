<?php

declare(strict_types=1);

namespace Ethernize\Strata\Support;

use Ethernize\Strata\Dialect\Dialect;
use Ethernize\Strata\Exception\InvalidQueryException;

/**
 * Table and column names. They cannot be bound as parameters, so they are
 * validated against a strict pattern and then quoted by the dialect. Anything
 * outside the pattern is refused, never escaped: there is no way to smuggle
 * SQL through an identifier.
 */
final class Identifier
{
    private const SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';
    private const CACHE_LIMIT = 2048;

    /** @var array<string, string> validated and quoted identifiers, by dialect and spelling */
    private static array $cache = [];

    private function __construct()
    {
    }

    /**
     * "name" or "table.name"; with $allowStar the last segment may be "*".
     */
    public static function quote(Dialect $dialect, string $identifier, bool $allowStar = false): string
    {
        // Only identifiers that passed validation are cached, so a hit is always safe to reuse.
        $key = $dialect->name() . ($allowStar ? '*' : ':') . $identifier;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $quoted = self::build($dialect, $identifier, $allowStar);

        if (count(self::$cache) < self::CACHE_LIMIT) {
            self::$cache[$key] = $quoted;
        }

        return $quoted;
    }

    private static function build(Dialect $dialect, string $identifier, bool $allowStar): string
    {
        $segments = explode('.', $identifier);

        if (count($segments) > 2) {
            throw new InvalidQueryException('Identifiers have at most two parts (table.column).');
        }

        $quoted = [];

        foreach ($segments as $index => $segment) {
            if ($allowStar && $segment === '*' && $index === count($segments) - 1) {
                $quoted[] = '*';
                continue;
            }

            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw new InvalidQueryException('Invalid identifier. Use letters, digits and underscores, starting with a letter or underscore.');
            }

            $quoted[] = $dialect->quoteIdentifier($segment);
        }

        return implode('.', $quoted);
    }

    /**
     * A table with an optional alias: "orders" or "orders o" or "orders AS o".
     *
     * @return array{string, ?string} the quoted FROM/JOIN text and the alias (or null)
     */
    public static function table(Dialect $dialect, string $spec): array
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]{0,62}(?:\.[A-Za-z_][A-Za-z0-9_]{0,62})?)(?:\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]{0,62}))?$/i', trim($spec), $m) !== 1) {
            throw new InvalidQueryException('Invalid table. Use "table" or "table alias".');
        }

        $alias = $m[2] ?? null;

        return [self::quote($dialect, $m[1]) . ($alias !== null ? ' AS ' . $dialect->quoteIdentifier($alias) : ''), $alias];
    }

    /**
     * A selectable or comparable expression. Only these forms exist, so nothing else can be smuggled in:
     *
     *   column          name, table.name, table.*, *
     *   aggregate       count(*), count(col), count(distinct col), sum(col), avg(col), min(col), max(col)
     *   alias (select)  any of the above followed by "as alias"
     */
    public static function expression(Dialect $dialect, string $expression, bool $allowAlias = false): string
    {
        $key = $dialect->name() . ($allowAlias ? '@' : '#') . $expression;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $text = trim($expression);
        $alias = null;

        if ($allowAlias && preg_match('/^(.+?)\s+AS\s+([A-Za-z_][A-Za-z0-9_]{0,62})$/is', $text, $m) === 1) {
            [$text, $alias] = [trim($m[1]), $m[2]];
        }

        if (preg_match('/^(count|sum|avg|min|max)\s*\(\s*(distinct\s+)?([^()]+?)\s*\)$/i', $text, $m) === 1) {
            $function = strtolower($m[1]);
            $distinct = $m[2] !== '';
            $argument = $m[3];

            if ($argument === '*') {
                if ($function !== 'count' || $distinct) {
                    throw new InvalidQueryException('Only count(*) accepts *.');
                }

                $inner = '*';
            } else {
                $inner = self::quote($dialect, $argument);
            }

            $quoted = strtoupper($function) . '(' . ($distinct ? 'DISTINCT ' : '') . $inner . ')';
        } else {
            $quoted = self::quote($dialect, $text, allowStar: true);
        }

        if ($alias !== null) {
            $quoted .= ' AS ' . $dialect->quoteIdentifier($alias);
        }

        if (count(self::$cache) < self::CACHE_LIMIT) {
            self::$cache[$key] = $quoted;
        }

        return $quoted;
    }

    /**
     * Validates a name that is stored by itself (a table in the schema API).
     */
    public static function assertName(string $name): string
    {
        if (preg_match(self::SEGMENT, $name) !== 1) {
            throw new InvalidQueryException('Invalid name. Use letters, digits and underscores, starting with a letter or underscore.');
        }

        return $name;
    }
}
