<?php

declare(strict_types=1);

namespace Ethernize\Strata\Migration;

use Ethernize\Strata\Connection;
use Ethernize\Strata\Exception\MigrationException;
use Ethernize\Strata\Schema\Schema;
use Throwable;

/**
 * Finds, runs, reverts and reports migrations for one connection.
 *
 * Files live in one directory and are named YYYYMMDDHHMMSS_snake_case_name.php;
 * the name without ".php" is the version and the order is alphabetical. The
 * applied versions are kept in a table (default "strata_migrations").
 *
 * Every method refuses to run outside the command line, so a migration can
 * never be triggered by an HTTP request.
 *
 * Each migration runs in its own transaction on engines where DDL is
 * transactional (SQLite, PostgreSQL). MySQL and MariaDB commit DDL
 * implicitly: a migration that fails there can leave part of its work applied,
 * and the error says so. The run stops at the first failure.
 */
final class Migrator
{
    private const FILE_PATTERN = '/^(\d{14})_([a-z][a-z0-9_]*)\.php$/';

    private readonly Schema $schema;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $directory,
        private readonly string $table = 'strata_migrations',
    ) {
        $this->schema = new Schema($connection);
    }

    /**
     * Migration files by version.
     *
     * @return array<string, string> version => absolute path
     */
    public function discover(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $found = [];

        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_file($this->directory . DIRECTORY_SEPARATOR . $name)) {
                continue;
            }

            if (!str_ends_with($name, '.php')) {
                continue;
            }

            if (preg_match(self::FILE_PATTERN, $name) !== 1) {
                throw new MigrationException(sprintf('"%s" is not a valid migration file name. Use YYYYMMDDHHMMSS_snake_case_name.php.', $name));
            }

            $found[substr($name, 0, -4)] = $this->directory . DIRECTORY_SEPARATOR . $name;
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * @return list<array{version: string, ran: bool, batch: ?int, missing: bool}> every known migration, in order
     */
    public function status(): array
    {
        $this->assertCli();
        $files = $this->discover();
        $applied = $this->tableExists() ? $this->applied() : [];
        $versions = array_unique(array_merge(array_keys($files), array_keys($applied)));
        sort($versions, SORT_STRING);

        return array_map(static fn (string $version): array => [
            'version' => $version,
            'ran' => isset($applied[$version]),
            'batch' => $applied[$version] ?? null,
            'missing' => !isset($files[$version]),
        ], $versions);
    }

    /**
     * @return list<string> versions that have not run yet
     */
    public function pending(): array
    {
        $this->assertCli();
        $applied = $this->tableExists() ? $this->applied() : [];

        return array_values(array_filter(array_keys($this->discover()), static fn (string $version): bool => !isset($applied[$version])));
    }

    /**
     * Runs every pending migration as one batch.
     *
     * @return list<string> the versions that ran
     * @throws MigrationException at the first failing migration; earlier ones stay applied
     */
    public function migrate(): array
    {
        $this->assertCli();
        $this->ensureTable();
        $applied = $this->applied();
        $files = $this->discover();
        $batch = ($applied === [] ? 0 : max($applied)) + 1;
        $ran = [];

        foreach ($files as $version => $path) {
            if (isset($applied[$version])) {
                continue;
            }

            $migration = $this->load($version, $path);

            $this->guarded($version, 'up', function () use ($migration, $version, $batch): void {
                $migration->up($this->schema);
                $this->connection->table($this->table)->insert(['version' => $version, 'batch' => $batch, 'executed_at' => gmdate('Y-m-d H:i:s')]);
            });

            $ran[] = $version;
        }

        return $ran;
    }

    /**
     * Reverts the last $batches batches, newest first.
     *
     * @return list<string> the versions that were reverted
     */
    public function rollback(int $batches = 1): array
    {
        $this->assertCli();

        if ($batches < 1) {
            throw new MigrationException('Roll back at least one batch.');
        }

        if (!$this->tableExists()) {
            return [];
        }

        $applied = $this->applied();

        if ($applied === []) {
            return [];
        }

        $lastBatch = max($applied);
        $threshold = $lastBatch - $batches + 1;
        $targets = array_keys(array_filter($applied, static fn (int $batch): bool => $batch >= $threshold));
        rsort($targets, SORT_STRING);
        $files = $this->discover();
        $reverted = [];

        foreach ($targets as $version) {
            if (!isset($files[$version])) {
                throw new MigrationException(sprintf('Cannot roll back %s: its file is missing.', $version));
            }

            $migration = $this->load($version, $files[$version]);

            $this->guarded($version, 'down', function () use ($migration, $version): void {
                $migration->down($this->schema);
                $this->connection->table($this->table)->where('version', $version)->delete();
            });

            $reverted[] = $version;
        }

        return $reverted;
    }

    /**
     * Creates an empty migration file and returns its path.
     */
    public function make(string $name, ?string $now = null): string
    {
        $this->assertCli();

        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new MigrationException('Use a snake_case name, for example create_notes_table.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MigrationException('Could not create the migrations directory.');
        }

        $path = $this->directory . DIRECTORY_SEPARATOR . ($now ?? gmdate('YmdHis')) . '_' . $name . '.php';

        if (file_exists($path)) {
            throw new MigrationException('A migration with this name already exists for this second. Try again.');
        }

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            use Ethernize\Strata\Migration\Migration;
            use Ethernize\Strata\Schema\Schema;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                }

                public function down(Schema $schema): void
                {
                }
            };

            PHP;

        file_put_contents($path, $template);

        return $path;
    }

    private function load(string $version, string $path): Migration
    {
        try {
            $migration = (static fn (): mixed => require $path)();
        } catch (Throwable $exception) {
            throw new MigrationException(sprintf('Migration %s could not be loaded: %s', $version, $exception::class), 0, $exception);
        }

        if (!$migration instanceof Migration) {
            throw new MigrationException(sprintf('Migration %s must return an instance of %s.', $version, Migration::class));
        }

        return $migration;
    }

    /**
     * @param callable(): void $work
     */
    private function guarded(string $version, string $direction, callable $work): void
    {
        $transactional = $this->connection->dialect()->transactionalDdl();

        try {
            if ($transactional) {
                $this->connection->transaction(static function () use ($work): void {
                    $work();
                });
            } else {
                $work();
            }
        } catch (Throwable $exception) {
            throw new MigrationException(
                sprintf(
                    'Migration %s failed during %s(): %s. %s',
                    $version,
                    $direction,
                    $exception::class,
                    $transactional
                        ? 'Its changes were rolled back and the run stopped.'
                        : 'This database commits DDL statements immediately, so part of the migration may have been applied. Check the schema before running again.',
                ),
                0,
                $exception,
            );
        }
    }

    private function ensureTable(): void
    {
        if ($this->tableExists()) {
            return;
        }

        $this->schema->create($this->table, function ($table): void {
            $table->id();
            $table->string('version', 255);
            $table->integer('batch');
            $table->dateTime('executed_at');
            $table->unique('version');
        });
    }

    private function tableExists(): bool
    {
        return $this->schema->hasTable($this->table);
    }

    /**
     * @return array<string, int> version => batch
     */
    private function applied(): array
    {
        $applied = [];

        foreach ($this->connection->table($this->table)->orderBy('id')->get() as $row) {
            $applied[(string) $row['version']] = (int) $row['batch'];
        }

        return $applied;
    }

    private function assertCli(): void
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            throw new MigrationException('Migrations run only from the command line, never during an HTTP request.');
        }
    }
}
