<?php

declare(strict_types=1);

namespace Ethernize\Strata\Cli;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\Generator\Plan;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Core\Cli\Generator\Resource;
use Ethernize\Core\Cli\Generator\ResourcePart;

/**
 * make:resource --migration: the migration that creates the table of the feature.
 */
final class MigrationPart implements ResourcePart
{
    public function name(): string
    {
        return 'migration';
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function options(): array
    {
        return ['soft-deletes', 'no-timestamps'];
    }

    /**
     * The path of a new migration: database/migrations/<UTC time>_<name>.php. The time is the one the
     * Migrator uses for its own files, so ordering is the same however a migration was made.
     */
    public static function path(Project $project, string $name): string
    {
        // A migration with this name already there (made on another second) counts as the same file: a second
        // "create_products" would make the next migrate fail because the table exists.
        $existing = glob($project->directory . '/database/migrations/??????????????_' . $name . '.php') ?: [];

        if ($existing !== []) {
            return $project->appPath('database/migrations/' . basename($existing[0]));
        }

        return $project->appPath('database/migrations/' . gmdate('YmdHis') . '_' . $name . '.php');
    }

    public function contribute(Resource $resource, Project $project, Plan $plan, Arguments $arguments): void
    {
        $plan->add(self::path($project, 'create_' . $resource->table), Stubs::migrationCreate($resource->table, $resource->fields, !$arguments->has('no-timestamps'), $arguments->has('soft-deletes')));
    }

    public function finish(Resource $resource, Project $project, Arguments $arguments): array
    {
        return ['Create the table: php bin/strata.php migrate'];
    }
}
