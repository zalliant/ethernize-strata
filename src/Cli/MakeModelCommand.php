<?php

declare(strict_types=1);

namespace Ethernize\Strata\Cli;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\Generator\Fields;
use Ethernize\Core\Cli\Generator\GeneratorCommand;
use Ethernize\Core\Cli\Generator\Names;
use Ethernize\Core\Cli\Generator\Plan;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Core\Cli\UsageError;

/**
 * `php ethernize make:model Product [--migration]`: a Strata model, optionally with the migration that
 * creates its table.
 */
final class MakeModelCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:model';
    }

    public function summary(): string
    {
        return 'Create a Strata model (and, with --migration, its table)';
    }

    public function usage(): string
    {
        return implode("\n", [
            'Usage: php ethernize make:model <Name> [--migration] [options]',
            '',
            'Creates src/Models/<Name>.php: a typed model with #[Table], one property per field, #[Fillable] and',
            '#[Expose] on them. With --migration it also creates the migration for the table.',
            '',
            '  --migration       also create the migration (database/migrations/<time>_create_<table>.php)',
            '  --fields=LIST     columns as name:type[?], comma separated (default: name:string)',
            '                    types: ' . implode(', ', Fields::TYPES) . '; "?" makes a field nullable',
            '  --table=NAME      table name when the plural of the model is not what you want',
            '  --soft-deletes    delete() marks the row with deleted_at instead of removing it',
            '  --no-timestamps   do not maintain created_at / updated_at',
            $this->commonUsage(),
            '',
            'Examples:',
            '  php ethernize make:model Product --migration',
            '  php ethernize make:model Product --migration --fields=name:string,price_cents:int,active:bool',
        ]);
    }

    protected function options(): array
    {
        return ModelPart::OPTIONS;
    }

    protected function plan(Arguments $arguments, Project $project, Plan $plan): void
    {
        ['namespace' => $sub, 'class' => $class] = $this->className($arguments, 'Product');
        $table = $arguments->option('table');

        if ($table !== null && preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new UsageError('--table takes a snake_case table name.');
        }

        $table ??= Names::table($class);
        $fields = Fields::parse($arguments->option('fields'));
        $timestamps = !$arguments->has('no-timestamps');
        $softDeletes = $arguments->has('soft-deletes');

        $plan->add($project->sourcePath(['Models', ...$sub, $class]), Stubs::model($project, ['Models', ...$sub], $class, $table, $fields, $timestamps, $softDeletes));

        if ($arguments->has('migration')) {
            $plan->add(MigrationPart::path($project, 'create_' . $table), Stubs::migrationCreate($table, $fields, $timestamps, $softDeletes));
            $plan->note('Create the table: php bin/strata.php migrate');
        }
    }
}
