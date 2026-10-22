<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model;

use Closure;
use Ethernize\Strata\Connection;
use Ethernize\Strata\Exception\ModelException;

/**
 * Where the static shortcuts (Product::find(1)) get their connection. It holds one resolver, installed by
 * StrataProvider, that asks the application's request-scoped DatabaseManager every time: no Connection, no
 * identity and no row is ever kept here, so nothing survives from one request to the next. The resolver is
 * replaced when an application boots, which is what a process serving one application needs. Anything else
 * (tests with several applications, scripts with several databases) names the connection: Product::on($db).
 */
final class ModelContext
{
    /** @var (Closure(?string): Connection)|null */
    private static ?Closure $resolver = null;

    private function __construct()
    {
    }

    /**
     * @param (Closure(?string): Connection)|null $resolver receives the connection name from #[Table(connection:)], or null for the default
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function connection(?string $name = null): Connection
    {
        if (self::$resolver === null) {
            throw new ModelException('No database is configured for models. Register StrataProvider, or use Model::on($connection).');
        }

        return (self::$resolver)($name);
    }

    public static function isConfigured(): bool
    {
        return self::$resolver !== null;
    }
}
