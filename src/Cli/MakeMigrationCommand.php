<?php

declare(strict_types=1);

namespace Ethernize\Strata\Cli;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\Generator\Fields;
use Ethernize\Core\Cli\Generator\GeneratorCommand;
use Ethernize\Core\Cli\Generator\Plan;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Core\Cli\UsageError;

/**
 * `php ethernize make:migration create_products`: a migration file, ready to run with `php bin/strata.php migrate`.
 */
final class MakeMigrationCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:migration';
    }

    public function summary(): string
    {
        return 'Create a migration file';
    }

    public function usage(): string
    {
        return implode("\n", [
            'Usage: php ethernize make:migration <snake_case_name> [options]',
            '',
            'Creates database/migrations/<time>_<name>.php. The name decides what it contains:',
            '  create_<table>                  creates the table (id, the --fields, timestamps)',
            '  add_<anything>  with --table    adds the --fields to an existing table',
            '  anything else                   an empty migration for you to write',
            '',
            '  --fields=LIST     columns as name:type[?], comma separated',
            '  --table=NAME      the table to alter (for add_*)',
            '  --no-timestamps   leave created_at / updated_at out of a create_* migration',
            '  --soft-deletes    add deleted_at to a create_* migration',
            $this->commonUsage(),
            '',
            'Examples:',
            '  php ethernize make:migration create_products --fields=name:string,price_cents:int',
            '  php ethernize make:migration add_note_to_products --table=products --fields=note:text?',
        ]);
    }

    protected function options(): array
    {
        return ['fields', 'table', 'no-timestamps', 'soft-deletes'];
    }

    protected function plan(Arguments $arguments, Project $project, Plan $plan): void
    {
        if (count($arguments->positional) !== 1 || preg_match('/^[a-z][a-z0-9_]*$/', $arguments->positional[0]) !== 1) {
            throw new UsageError('Give a snake_case name. Example: php ethernize make:migration create_products');
        }

        $name = $arguments->positional[0];
        $hasFields = $arguments->has('fields');

        if (str_starts_with($name, 'create_')) {
            $table = substr($name, strlen('create_'));
            $contents = Stubs::migrationCreate($table, $hasFields ? Fields::parse($arguments->option('fields')) : Fields::none(), !$arguments->has('no-timestamps'), $arguments->has('soft-deletes'));
        } elseif (str_starts_with($name, 'add_') && $arguments->has('table')) {
            if (!$hasFields) {
                throw new UsageError('add_* migrations need --fields=name:type to know what to add.');
            }

            $table = (string) $arguments->option('table');

            if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
                throw new UsageError('--table takes a snake_case table name.');
            }

            $contents = Stubs::migrationAlter($table, Fields::parse($arguments->option('fields')));
        } else {
            $contents = Stubs::migrationBlank();
        }

        $plan->add(MigrationPart::path($project, $name), $contents);
        $plan->note('Run it: php bin/strata.php migrate');
    }
}
