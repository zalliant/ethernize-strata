<?php

declare(strict_types=1);

namespace Ethernize\Strata\Cli;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\Generator\Plan;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Core\Cli\Generator\Resource;
use Ethernize\Core\Cli\Generator\ResourcePart;

/**
 * make:resource --model: the model of the feature, with the fields of --fields.
 */
final class ModelPart implements ResourcePart
{
    public const OPTIONS = ['migration', 'fields', 'table', 'soft-deletes', 'no-timestamps'];

    public function name(): string
    {
        return 'model';
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function options(): array
    {
        return ['soft-deletes', 'no-timestamps'];
    }

    public function contribute(Resource $resource, Project $project, Plan $plan, Arguments $arguments): void
    {
        $plan->add(
            $project->sourcePath([...$resource->folder('Models'), $resource->name]),
            Stubs::model($project, $resource->folder('Models'), $resource->name, $resource->table, $resource->fields, !$arguments->has('no-timestamps'), $arguments->has('soft-deletes')),
        );
    }

    public function finish(Resource $resource, Project $project, Arguments $arguments): array
    {
        return [];
    }
}
