<?php

declare(strict_types=1);

namespace Ethernize\Strata\Cli;

use Ethernize\Core\Application;
use Ethernize\Core\Cli\DoctorCheck;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Strata\Config\DatabaseConfig;
use Throwable;

/**
 * `php ethernize doctor` for Strata: the database configuration is valid, the PDO driver of every connection is
 * loaded, a SQLite file can be created, and the migrations folder exists. No connection is opened.
 */
final class StrataDoctorCheck implements DoctorCheck
{
    public function run(Application $app, Project $project): array
    {
        if (!$app->config()->has('database')) {
            return [];
        }

        try {
            $config = DatabaseConfig::fromArray((array) $app->config()->get('database', []));
        } catch (Throwable $error) {
            return [['FAIL', 'config/database.php is not valid: ' . $error->getMessage(), 'Fix the setting named in the message; passwords and hosts belong in .env through the "_env" keys.']];
        }

        $rows = [];

        foreach ($config->names() as $name) {
            $settings = $config->connection($name);
            $driver = (string) ($settings['driver'] ?? '');
            $extension = 'pdo_' . ($driver === 'mariadb' ? 'mysql' : $driver);

            if (!extension_loaded($extension)) {
                $rows[] = ['FAIL', sprintf('database connection "%s": the PHP extension %s is not loaded', $name, $extension), 'Enable it in php.ini.'];
            } elseif ($driver === 'sqlite') {
                $path = (string) ($settings['database'] ?? '');
                $file = $path === ':memory:' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1 ? $path : $app->basePath($path);
                $rows[] = $path === ':memory:' || (is_dir(dirname($file)) && is_writable(dirname($file)))
                    ? ['ok', sprintf('database connection "%s" (sqlite, %s)', $name, $path)]
                    : ['FAIL', sprintf('database connection "%s": the folder of the SQLite file is missing or not writable', $name), 'Create ' . dirname($file) . ' and make it writable.'];
            } else {
                $rows[] = ['ok', sprintf('database connection "%s" (%s): driver loaded, no connection opened', $name, $driver)];
            }
        }

        $migrations = $app->basePath($config->migrationsPath());
        $rows[] = is_dir($migrations)
            ? ['ok', sprintf('migrations folder %s (%d file%s)', $config->migrationsPath(), count(glob($migrations . '/*.php') ?: []), count(glob($migrations . '/*.php') ?: []) === 1 ? '' : 's')]
            : ['warn', 'no migrations folder (' . $config->migrationsPath() . ')', 'It is created by php ethernize make:migration.'];

        return $rows;
    }
}
