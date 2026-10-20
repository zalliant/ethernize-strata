<?php

declare(strict_types=1);

namespace Ethernize\Strata;

use Ethernize\Core\Application;
use Ethernize\Core\BootableProvider;
use Ethernize\Core\Config\Environment;
use Ethernize\Core\Container\Container;
use Ethernize\Core\Events\Terminated;
use Ethernize\Strata\Config\DatabaseConfig;

/**
 * Registers Strata. List it in app.providers and add config/database.php.
 *
 * Services:
 *   DatabaseConfig    shared, validated configuration (no secrets in it)
 *   DatabaseManager   one per request; opens nothing until a query runs
 *   Connection        the default connection of that manager (also per request)
 *
 * When the request ends (Terminated) every connection is closed and open
 * transactions are rolled back, and the next request starts with a new manager.
 *
 * Credentials come from environment variables named by "<field>_env" settings.
 * In development the .env file is read as well; with the Manifest in use, only
 * the real process environment is, like APP_MANIFEST itself.
 */
final class StrataProvider implements BootableProvider
{
    private ?DatabaseManager $current = null;
    private ?Environment $dotenv = null;

    public function register(Container $container, Application $app): void
    {
        $container->singleton(DatabaseConfig::class, static fn (): DatabaseConfig => DatabaseConfig::fromArray((array) $app->config()->get('database', [])));

        $container->scoped(DatabaseManager::class, function (Container $c) use ($app): DatabaseManager {
            // A manager left over from a request that never reached Terminated must not keep its connections.
            $this->current?->disconnectAll();

            return $this->current = new DatabaseManager(
                $c->get(DatabaseConfig::class),
                fn (string $name): ?string => $this->environment($app, $name),
                $app->basePath(),
            );
        });

        $container->scoped(Connection::class, static fn (Container $c): Connection => $c->get(DatabaseManager::class)->connection());
    }

    public function boot(Application $app): void
    {
        $app->events()->listen(Terminated::class, function (): void {
            $this->current?->disconnectAll();
            $this->current = null;
        });

        if ($app->config()->has('database')) {
            $app->addManifestContributor(new StrataManifestContributor());
        }
    }

    private function environment(Application $app, string $name): ?string
    {
        if ($app->isCompiled()) {
            $value = getenv($name);

            if ($value === false) {
                $value = $_SERVER[$name] ?? null;
            }

            return is_string($value) ? $value : null;
        }

        return ($this->dotenv ??= Environment::load($app->basePath()))->get($name);
    }
}
