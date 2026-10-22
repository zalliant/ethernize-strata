<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Ethernize\Strata\Exception\ModelException;
use Ethernize\Strata\Model\Attribute\Cast;
use Ethernize\Strata\Model\Attribute\Expose;
use Ethernize\Strata\Model\Attribute\Fillable;
use Ethernize\Strata\Model\Attribute\Ignore;
use Ethernize\Strata\Model\Attribute\Table;
use Ethernize\Strata\Support\Identifier;
use JsonException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * What a model class declares, read once by reflection and validated strictly. It also holds the conversions
 * between database values and the declared property types, so a model never depends on how a driver happens
 * to return a column (SQLite gives integers, MySQL may give strings).
 *
 * @internal
 */
final class ModelMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    private static ?DateTimeZone $utc = null;

    private ?ReflectionClass $reflector = null;

    /**
     * @param array<string, array{kind: string, nullable: bool, enum: ?class-string<BackedEnum>, fillable: bool, expose: bool}> $columns property name => description
     * @param list<string> $exposedRelations
     */
    private function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly string $key,
        public readonly bool $incrementing,
        public readonly ?string $connection,
        public readonly bool $timestamps,
        public readonly bool $softDeletes,
        public readonly ?string $version,
        public readonly bool $lazyLoading,
        public readonly array $columns,
        public readonly array $exposedRelations,
    ) {
    }

    /**
     * @param class-string<Model> $class
     */
    public static function for(string $class): self
    {
        return self::$cache[$class] ??= self::build($class);
    }

    /**
     * @param class-string<Model> $class
     */
    private static function build(string $class): self
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isSubclassOf(Model::class) || !$reflection->isInstantiable()) {
            throw new ModelException(sprintf('%s must be a concrete subclass of %s.', $class, Model::class));
        }

        $table = ($reflection->getAttributes(Table::class)[0] ?? null)?->newInstance() ?? new Table();
        $name = $table->name ?? self::tableName($reflection->getShortName());

        try {
            Identifier::assertName($name);
            Identifier::assertName($table->key);
        } catch (\Throwable) {
            throw new ModelException(sprintf('%s: the table and key names may only contain letters, digits and underscores.', $class));
        }

        $columns = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getAttributes(Ignore::class) !== []) {
                continue;
            }

            if (in_array($property->getName(), ['connection', 'exists', 'original', 'relations'], true)) {
                throw new ModelException(sprintf('%s::$%s uses a name the model base class reserves. Rename the property.', $class, $property->getName()));
            }

            $where = sprintf('%s::$%s', $class, $property->getName());

            if ($property->isReadOnly()) {
                throw new ModelException($where . ' cannot be readonly: models are updated in place. Mark it #[Ignore] if it is not a column.');
            }

            $type = $property->getType();

            if (!$type instanceof ReflectionNamedType) {
                throw new ModelException($where . ' needs a single declared type (int, float, string, bool, array, DateTimeImmutable or a backed enum), or #[Ignore] if it is not a column.');
            }

            try {
                Identifier::assertName($property->getName());
            } catch (\Throwable) {
                throw new ModelException($where . ' is not a valid column name.');
            }

            $cast = ($property->getAttributes(Cast::class)[0] ?? null)?->newInstance()->as;
            $columns[$property->getName()] = self::describe($where, $type, $cast) + [
                'fillable' => $property->getAttributes(Fillable::class) !== [],
                'expose' => $property->getAttributes(Expose::class) !== [],
            ];
        }

        $key = $table->key;

        if (!isset($columns[$key]) || !in_array($columns[$key]['kind'], ['int', 'string'], true)) {
            throw new ModelException(sprintf('%s needs a public int or string property $%s for its primary key.', $class, $key));
        }

        if ($columns[$key]['fillable']) {
            throw new ModelException(sprintf('%s::$%s is the primary key and cannot be #[Fillable].', $class, $key));
        }

        foreach ($table->timestamps ? ['created_at', 'updated_at'] : [] as $stamp) {
            self::requireDate($class, $columns, $stamp, 'timestamps');
        }

        if ($table->softDeletes) {
            self::requireDate($class, $columns, 'deleted_at', 'softDeletes');
        }

        if ($table->version !== null && (($columns[$table->version]['kind'] ?? null) !== 'int' || $columns[$table->version]['nullable'])) {
            throw new ModelException(sprintf('%s: the version column "%s" must be a public non-nullable int property.', $class, $table->version));
        }

        foreach (['created_at', 'updated_at', 'deleted_at'] as $managed) {
            if (isset($columns[$managed]) && $columns[$managed]['fillable']) {
                throw new ModelException(sprintf('%s::$%s is managed by the model and cannot be #[Fillable].', $class, $managed));
            }
        }

        if ($table->version !== null && $columns[$table->version]['fillable']) {
            throw new ModelException(sprintf('%s::$%s is the version column and cannot be #[Fillable].', $class, $table->version));
        }

        $relations = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Expose::class) !== [] && !$method->isStatic()) {
                $relations[] = $method->getName();
            }
        }

        return new self($class, $name, $key, $table->incrementing, $table->connection, $table->timestamps, $table->softDeletes, $table->version, $table->lazyLoading, $columns, $relations);
    }

    /**
     * @return array{kind: string, nullable: bool, enum: ?class-string<BackedEnum>}
     */
    private static function describe(string $where, ReflectionNamedType $type, ?string $cast): array
    {
        $name = $type->getName();
        $nullable = $type->allowsNull();

        if ($cast !== null && !in_array($cast, ['date', 'json'], true)) {
            throw new ModelException($where . ': #[Cast] takes "date" or "json".');
        }

        $kind = match (true) {
            $name === 'int', $name === 'float', $name === 'string', $name === 'bool' => $name,
            $name === 'array' => 'json',
            is_a($name, DateTimeInterface::class, true) => $cast === 'date' ? 'date' : 'datetime',
            enum_exists($name) && is_a($name, BackedEnum::class, true) => 'enum',
            default => throw new ModelException($where . ' has a type models cannot store. Use int, float, string, bool, array, DateTimeImmutable or a backed enum, or #[Ignore].'),
        };

        if (($cast === 'json' && $kind !== 'json') || ($cast === 'date' && $kind !== 'date')) {
            throw new ModelException($where . ': this #[Cast] does not fit the declared type.');
        }

        if ($kind === 'datetime' || $kind === 'date') {
            if ($name !== DateTimeImmutable::class && $name !== DateTimeInterface::class) {
                throw new ModelException($where . ' must be declared DateTimeImmutable: dates in models are immutable values.');
            }
        }

        return ['kind' => $kind, 'nullable' => $nullable, 'enum' => $kind === 'enum' ? $name : null];
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     */
    private static function requireDate(string $class, array $columns, string $name, string $option): void
    {
        $column = $columns[$name] ?? null;

        if ($column === null || !in_array($column['kind'], ['datetime'], true) || !$column['nullable']) {
            throw new ModelException(sprintf('%s: %s needs a public ?DateTimeImmutable property $%s.', $class, $option, $name));
        }
    }

    /**
     * A new object of the model class. Models have no constructor of their own: declared defaults apply,
     * nothing else runs.
     */
    public function instantiate(): Model
    {
        return ($this->reflector ??= new ReflectionClass($this->class))->newInstanceWithoutConstructor();
    }

    private static function tableName(string $short): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));

        return match (true) {
            preg_match('/[^aeiou]y$/', $snake) === 1 => substr($snake, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/', $snake) === 1 => $snake . 'es',
            default => $snake . 's',
        };
    }

    // Conversions

    /**
     * A database value as the declared PHP type.
     *
     * @param array{kind: string, nullable: bool, enum: ?class-string<BackedEnum>} $column
     */
    public function fromStorage(string $property, array $column, mixed $value): mixed
    {
        if ($value === null) {
            if ($column['nullable']) {
                return null;
            }

            throw new ModelException(sprintf('%s::$%s is not nullable but the database returned NULL.', $this->class, $property));
        }

        $converted = match ($column['kind']) {
            'int' => is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : (is_float($value) && floor($value) === $value ? (int) $value : (is_bool($value) ? (int) $value : null))),
            'float' => is_float($value) ? $value : (is_int($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null),
            'string' => is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null),
            'bool' => is_bool($value) ? $value : (match (true) { $value === 1, $value === '1', $value === 't', $value === 'true' => true, $value === 0, $value === '0', $value === 'f', $value === 'false' => false, default => null }),
            'datetime' => $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc()) : (is_string($value) ? self::parseDateTime($value) : null),
            'date' => $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : (is_string($value) ? self::parseDate($value) : null),
            'json' => is_array($value) ? $value : (is_string($value) ? self::parseJson($value) : null),
            'enum' => $value instanceof BackedEnum ? $value : ((is_int($value) || is_string($value)) ? $column['enum']::tryFrom($value) : null),
        };

        if ($converted === null) {
            throw new ModelException(sprintf('%s::$%s: the stored value is not a valid %s.', $this->class, $property, $column['kind']));
        }

        return $converted;
    }

    /**
     * A value from outside (mass assignment, a form) as the declared PHP type. Same rules as fromStorage(),
     * plus the conventions of forms: an empty string means null for a nullable non-text property.
     *
     * @param array{kind: string, nullable: bool, enum: ?class-string<BackedEnum>} $column
     */
    public function fromInput(string $property, array $column, mixed $value): mixed
    {
        if ($value === '' && $column['nullable'] && $column['kind'] !== 'string') {
            return null;
        }

        if ($column['kind'] === 'bool' && is_string($value)) {
            $value = match (strtolower($value)) {
                'on', 'yes' => '1',
                'off', 'no', '' => '0',
                default => $value,
            };
        }

        if ($column['kind'] === 'string' && !is_string($value) && !is_int($value) && !is_float($value) && $value !== null) {
            throw new ModelException(sprintf('%s::$%s takes text.', $this->class, $property));
        }

        try {
            return $this->fromStorage($property, $column, $value);
        } catch (ModelException) {
            throw new ModelException(sprintf('The value for %s::$%s is not a valid %s.', $this->class, $property, $column['kind']));
        }
    }

    /**
     * A property value as what is bound in SQL.
     *
     * @param array{kind: string, nullable: bool, enum: ?class-string<BackedEnum>} $column
     */
    public function toStorage(array $column, mixed $value): bool|int|float|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($column['kind']) {
            'datetime' => DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc())->format('Y-m-d H:i:s'),
            'date' => $value->format('Y-m-d'),
            'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enum' => $value->value,
            default => $value,
        };
    }

    /**
     * A property value as what toArray() and JSON show.
     *
     * @param array{kind: string, nullable: bool, enum: ?class-string<BackedEnum>} $column
     */
    public function toOutput(array $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column['kind']) {
            'datetime' => DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc())->format(DATE_ATOM),
            'date' => $value->format('Y-m-d'),
            'enum' => $value->value,
            default => $value,
        };
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    private static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }

    private static function parseDateTime(string $value): ?DateTimeImmutable
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i:s.u', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:s.u'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, self::utc());

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value) === 1) {
            try {
                return (new DateTimeImmutable($value))->setTimezone(self::utc());
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), self::utc());

        return $date !== false && DateTimeImmutable::getLastErrors() === false && strlen($value) >= 10 ? $date : null;
    }

    /**
     * @return array<mixed>|null
     */
    private static function parseJson(string $value): ?array
    {
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
