<?php

declare(strict_types=1);

namespace Ethernize\Strata\Migration;

use Ethernize\Strata\Exception\MigrationException;
use Ethernize\Strata\Schema\Schema;

/**
 * A migration file returns an instance of a subclass:
 *
 *   return new class extends Migration {
 *       public function up(Schema $schema): void { ... }
 *       public function down(Schema $schema): void { ... }
 *   };
 *
 * Migrations run only from the command line.
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;

    /**
     * Reverts up(). The default refuses, so a migration is irreversible unless it says otherwise.
     */
    public function down(Schema $schema): void
    {
        throw new MigrationException('This migration does not define down() and cannot be rolled back.');
    }
}
