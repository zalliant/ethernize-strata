<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Attribute;

use Attribute;

/**
 * Maps a model class to its table. Everything a model does with the database is declared here and on its
 * typed public properties; nothing is guessed at run time from the schema.
 *
 *   #[Table('products', softDeletes: true, timestamps: true)]
 *   final class Product extends Model { ... }
 *
 * $key              primary key column; it must be a public int or string property of the model
 * $incrementing     true when the database generates the key (an auto-increment id)
 * $connection       a named connection of config/database.php (null: the default one)
 * $timestamps       maintain created_at and updated_at (nullable DateTimeImmutable properties)
 * $softDeletes      delete() sets deleted_at instead of removing the row, and queries skip those rows
 * $version          an int column for optimistic concurrency: updates fail when the row changed meanwhile
 * $lazyLoading      allow reading a relation that was not loaded (one query per access). Off by default
 *                   because it hides N+1 queries; use with() or load() instead.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly string $key = 'id',
        public readonly bool $incrementing = true,
        public readonly ?string $connection = null,
        public readonly bool $timestamps = false,
        public readonly bool $softDeletes = false,
        public readonly ?string $version = null,
        public readonly bool $lazyLoading = false,
    ) {
    }
}
