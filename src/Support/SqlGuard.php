<?php

declare(strict_types=1);

namespace Ethernize\Strata\Support;

use Ethernize\Strata\Exception\InvalidQueryException;

/**
 * Checks the shape of hand-written SQL before it is prepared: the number of
 * placeholders must match the bindings, and there must be one statement only.
 *
 * It is a seatbelt for mistakes, not a sanitizer. SQL text passed to the raw
 * methods must be written by the developer; values always go in $bindings.
 */
final class SqlGuard
{
    private function __construct()
    {
    }

    /**
     * @param array<int|string, mixed> $bindings positional (list) or named (map), not both
     * @throws InvalidQueryException
     */
    public static function assertValid(string $sql, array $bindings, bool $backslashEscapes): void
    {
        $positional = 0;
        $named = [];
        $length = strlen($sql);
        $statementEnded = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($statementEnded && !ctype_space($char) && !self::startsComment($sql, $i)) {
                throw new InvalidQueryException('Only one statement per call is allowed.');
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $i = self::skipQuoted($sql, $i, $char, $backslashEscapes);
                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    throw new InvalidQueryException('Unterminated comment in SQL.');
                }

                $i = $end + 1;
                continue;
            }

            if ($char === ';') {
                $statementEnded = true;
                continue;
            }

            if ($char === '?') {
                $positional++;
                continue;
            }

            if ($char === ':') {
                if (($sql[$i + 1] ?? '') === ':') {
                    $i++; // a PostgreSQL cast, not a placeholder

                    continue;
                }

                if (preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $match, 0, $i) === 1) {
                    $named[$match[1]] = true;
                    $i += strlen($match[0]) - 1;
                }
            }
        }

        if ($positional > 0 && $named !== []) {
            throw new InvalidQueryException('Do not mix ? and :name placeholders.');
        }

        if ($named !== []) {
            $keys = array_map(static fn (int|string $key): string => ltrim((string) $key, ':'), array_keys($bindings));
            sort($keys);
            $expected = array_keys($named);
            sort($expected);

            if ($keys !== $expected) {
                throw new InvalidQueryException('The named bindings do not match the :name placeholders.');
            }

            return;
        }

        if (!array_is_list($bindings) || count($bindings) !== $positional) {
            throw new InvalidQueryException(sprintf('The SQL has %d placeholder(s) but %d positional binding(s) were given.', $positional, count($bindings)));
        }
    }

    private static function skipQuoted(string $sql, int $start, string $quote, bool $backslashEscapes): int
    {
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($backslashEscapes && $sql[$i] === '\\' && $quote !== '`') {
                $i++;
                continue;
            }

            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++; // doubled quote inside the literal

                    continue;
                }

                return $i;
            }
        }

        throw new InvalidQueryException('Unterminated quote in SQL.');
    }

    private static function startsComment(string $sql, int $i): bool
    {
        return ($sql[$i] === '-' && ($sql[$i + 1] ?? '') === '-') || ($sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*');
    }
}
