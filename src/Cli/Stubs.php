<?php

declare(strict_types=1);

namespace Ethernize\Strata\Cli;

use Ethernize\Core\Cli\Generator\Fields;
use Ethernize\Core\Cli\Generator\Project;

/**
 * The code written by `make:model` and `make:migration`, from the same description of the fields, so a model
 * and its migration always agree on the columns.
 */
final class Stubs
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $namespace folders below src/ ("Models", then sub-folders)
     */
    public static function model(Project $project, array $namespace, string $class, string $table, Fields $fields, bool $timestamps, bool $softDeletes): string
    {
        $ns = $project->namespaceFor($namespace);
        $imports = ['Ethernize\Strata\Model\Attribute\Expose', 'Ethernize\Strata\Model\Attribute\Table', 'Ethernize\Strata\Model\Model'];
        $properties = ["    #[Expose]\n    public int \$id;"];

        if ($fields->fields !== []) {
            $imports[] = 'Ethernize\Strata\Model\Attribute\Fillable';
        }

        foreach ($fields->fields as $field) {
            [$type, $default, $cast] = self::phpType($field['type'], $field['nullable']);
            $attributes = ['Fillable', 'Expose'];

            if ($cast !== null) {
                $attributes[] = sprintf("Cast('%s')", $cast);
                $imports[] = 'Ethernize\Strata\Model\Attribute\Cast';
            }

            if (str_contains($type, 'DateTimeImmutable')) {
                $imports[] = 'DateTimeImmutable';
            }

            $properties[] = sprintf("    #[%s]\n    public %s \$%s%s;", implode(', ', $attributes), $type, $field['name'], $default === null ? '' : ' = ' . $default);
        }

        if ($timestamps || $softDeletes) {
            $imports[] = 'DateTimeImmutable';
        }

        $stamps = [];

        foreach (array_filter(['created_at' => $timestamps, 'updated_at' => $timestamps, 'deleted_at' => $softDeletes]) as $name => $_) {
            $stamps[] = sprintf('    public ?DateTimeImmutable $%s = null;', $name);
        }

        $options = ["'" . $table . "'"];

        if ($timestamps) {
            $options[] = 'timestamps: true';
        }

        if ($softDeletes) {
            $options[] = 'softDeletes: true';
        }

        $attribute = implode(', ', $options);
        $imports = array_values(array_unique($imports));
        sort($imports);
        $use = implode("\n", array_map(static fn (string $import): string => 'use ' . $import . ';', $imports));
        $body = implode("\n\n", $properties) . ($stamps === [] ? '' : "\n\n" . implode("\n", $stamps));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            {$use}

            /**
             * One typed public property per column: the declared type is how a value is stored and read.
             * #[Fillable] lets create() and fill() set a property from an array; #[Expose] puts it in toArray()
             * and JSON. A property with neither can only be set in code and is never shown.
             */
            #[Table({$attribute})]
            final class {$class} extends Model
            {
            {$body}
            }

            PHP;
    }

    /**
     * @return string the migration file contents
     */
    public static function migrationCreate(string $table, Fields $fields, bool $timestamps, bool $softDeletes): string
    {
        $lines = ['            $t->id();'];

        foreach ($fields->fields as $field) {
            $lines[] = '            ' . self::column($field);
        }

        if ($timestamps) {
            $lines[] = '            $t->timestamps();';
        }

        if ($softDeletes) {
            $lines[] = '            $t->softDeletes();';
        }

        $columns = implode("\n", $lines);

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Ethernize\Strata\Migration\Migration;
            use Ethernize\Strata\Schema\Blueprint;
            use Ethernize\Strata\Schema\Schema;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
                    \$schema->create('{$table}', function (Blueprint \$t): void {
            {$columns}
                    });
                }

                public function down(Schema \$schema): void
                {
                    \$schema->dropIfExists('{$table}');
                }
            };

            PHP;
    }

    public static function migrationAlter(string $table, Fields $fields): string
    {
        $add = implode("\n", array_map(static fn (array $field): string => '            ' . self::column($field), $fields->fields));
        $drop = implode(', ', array_map(static fn (array $field): string => "'" . $field['name'] . "'", $fields->fields));

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Ethernize\Strata\Migration\Migration;
            use Ethernize\Strata\Schema\Blueprint;
            use Ethernize\Strata\Schema\Schema;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
                    \$schema->table('{$table}', function (Blueprint \$t): void {
            {$add}
                    });
                }

                public function down(Schema \$schema): void
                {
                    \$schema->table('{$table}', function (Blueprint \$t): void {
                        \$t->dropColumn({$drop});
                    });
                }
            };

            PHP;
    }

    public static function migrationBlank(): string
    {
        return <<<'PHP'
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
    }

    /**
     * @param array{name: string, type: string, nullable: bool} $field
     */
    private static function column(array $field): string
    {
        $call = match ($field['type']) {
            'string' => sprintf("\$t->string('%s')", $field['name']),
            'text', 'json' => sprintf("\$t->text('%s')", $field['name']),
            'int' => sprintf("\$t->integer('%s')", $field['name']),
            'bigint' => sprintf("\$t->bigInteger('%s')", $field['name']),
            'float' => sprintf("\$t->float('%s')", $field['name']),
            'bool' => sprintf("\$t->boolean('%s')", $field['name']),
            'date' => sprintf("\$t->date('%s')", $field['name']),
            'datetime' => sprintf("\$t->dateTime('%s')", $field['name']),
        };

        if ($field['nullable']) {
            $call .= '->nullable()';
        } elseif ($field['type'] === 'bool') {
            $call .= '->default(false)';
        }

        return $call . ';';
    }

    /**
     * @return array{string, ?string, ?string} the PHP type, the default value, and the #[Cast] it needs
     */
    private static function phpType(string $type, bool $nullable): array
    {
        [$php, $default, $cast] = match ($type) {
            'string', 'text' => ['string', "''", null],
            'int', 'bigint' => ['int', '0', null],
            'float' => ['float', '0.0', null],
            'bool' => ['bool', 'false', null],
            'json' => ['array', '[]', 'json'],
            'date' => ['DateTimeImmutable', null, 'date'],
            'datetime' => ['DateTimeImmutable', null, null],
        };

        return $nullable ? ['?' . $php, 'null', $cast] : [$php, $default, $cast];
    }
}
