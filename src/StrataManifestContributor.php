<?php

declare(strict_types=1);

namespace Ethernize\Strata;

use Ethernize\Core\Application;
use Ethernize\Core\Manifest\ManifestContribution;
use Ethernize\Core\Manifest\ManifestContributor;
use Ethernize\Strata\Config\DatabaseConfig;

/**
 * Checks the database configuration while the Manifest is built, so a problem
 * (an unknown driver, a literal password) fails the build instead of the first
 * request. It never opens a connection and never reads credentials.
 *
 * The contribution holds only connection names and drivers.
 */
final class StrataManifestContributor implements ManifestContributor
{
    public function key(): string
    {
        return 'strata';
    }

    public function contribute(Application $application): ManifestContribution
    {
        $config = DatabaseConfig::fromArray((array) $application->config()->get('database', []));

        return new ManifestContribution(['connections' => $config->summary(), 'default' => $config->defaultName()]);
    }
}
