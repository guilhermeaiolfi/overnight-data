Implement Mapper Phase 2 

Inspect the current repository and existing Overnight Mapper implementation first.

## Goal

Add the structural mapper runtime and fluent `map()` entry point.

Do not implement the full DTO mapper system yet.

## Add

* `MapperInterface`
* abstract `Mapper`
* `MapperManager`
* immutable `MappingContext`
* immutable `MapBuilder`
* `map()` function
* shallow `ArrayToStdClassMapper`
* shallow `StdClassToArrayMapper`
* mapper exceptions and tests

## Mapper contract

Every structural mapper must expose:

```php
public static function canMap(
    mixed $source,
    mixed $target,
    MappingContext $context,
): bool;

public function map(
    mixed $source,
    mixed $target,
    MappingContext $context,
): mixed;

public static function defaultRepresentations(): array;
```

Default structural mappers extend:

```php
abstract class Mapper implements MapperInterface
{
    public function __construct(
        protected readonly ConversionGateway $gateway,
    ) {
    }

    public static function defaultRepresentations(): array
    {
        return [];
    }
}
```

## MapperManager

Keep registration, selection, lazy construction, and instance caching in one class.

Do not introduce separate registry, provider, or factory classes.

Do not introduce `MappingKind`.

The manager must:

* store registered mapper class strings;
* preserve registration order;
* reject duplicate registrations;
* select using static `canMap()`;
* lazily instantiate only the selected mapper;
* cache one instance per mapper class;
* construct default mappers as `new $mapper($gateway)`;
* support an optional custom constructor closure;
* support explicit `using()` selection without scanning unrelated mappers;
* expose `warmUp()`;
* expose `clear()` that clears instances but preserves registrations.

The custom constructor has this shape:

```php
Closure(
    class-string<MapperInterface>,
    ConversionGateway
): MapperInterface
```

Preserve the existing `ConversionGateway` constructor where practical.

Allow mapper construction to be configured through `MapperManager` before the first mapper is instantiated. Reject changing the constructor after instances have already been created.

`MapperManager::createDefault()` should register only:

```php
ArrayToStdClassMapper::class
StdClassToArrayMapper::class
```

The remaining production mappers belong to Phase 3.

## ConversionGateway integration

`ConversionGateway` owns one `MapperManager`.

Add:

```php
public function getMappers(): MapperManager;
```

Do not add application or container lookup.

Do not move the ambient default into `ConversionGateway`; `Mapping` remains the only default gateway holder.

## MappingContext

Include only per-operation mapping state:

* active gateway;
* source representation;
* output representation;
* explicitly selected mapper class;
* mapper arguments;
* collection mode;
* nested path.

Provide immutable `with...()` methods and getters using `getSomething()` naming.

Do not add resolver pipelines, Query state, relation state, REST state, or ORM state.

## MapBuilder

Preserve the fluent API:

```php
map($source)->to(Target::class);

map($source)
    ->from(WireRepresentation::class)
    ->to(Target::class);

map($source)
    ->as(WireRepresentation::class)
    ->toArray();

map($source)
    ->using(CustomMapper::class, ...$arguments)
    ->to($target);

map($source)
    ->collection()
    ->to(Target::class);

map($source)->toArray();
map($source)->toJson();
```

Configuration methods must return clones:

* `from()`
* `as()`
* `using()`
* `args()`
* `collection()`

Passing a representation class to `to()` must fail and direct the caller to `as()`.

Nested and collection mapping must retain the active gateway.

## `map()` function

Add:

```php
function map(
    mixed $source,
    ?string $from = null,
    ?ConversionGateway $gateway = null,
): MapBuilder;
```

Resolution order:

1. explicitly supplied gateway;
2. `Mapping::getDefaultGateway()`.

Configure Composer function autoloading correctly.

Normal application usage must remain:

```php
map($source)->to(Target::class);
```

## Collection mode

Implement collection mapping generically in `MapperManager`.

When `MappingContext::isCollection()` is true:

* require an iterable source;
* map each item using a non-collection child context;
* return a list;
* return an empty list for an empty iterable.

Individual mappers should map one item and should not duplicate collection iteration.

## Minimal built-in mappers

`ArrayToStdClassMapper`:

* maps one array to `stdClass`;
* shallow only;
* no nested DTO logic;
* no attributes;
* no FieldType inference.

`StdClassToArrayMapper`:

* maps one `stdClass` to an array;
* shallow only;
* no hidden fields or attributes.

Representation values must still be carried through `MappingContext`, but these shallow untyped mappers do not need to infer scalar FieldTypes.

## Required tests

Cover:

* registration does not instantiate a mapper;
* only the selected mapper is instantiated;
* the selected mapper instance is reused;
* `clear()` causes a new instance on the next mapping;
* `warmUp()` constructs all registered mappers;
* duplicate registration fails;
* invalid mapper classes fail;
* custom constructor closure works;
* changing the constructor after instantiation fails;
* automatic `canMap()` selection;
* explicit `using()` selection;
* explicit `using()` rejects an incompatible mapper;
* no mapper found error;
* immutable builder methods;
* `map()` uses the configured default gateway;
* an explicit gateway overrides the default;
* array to `stdClass`;
* `stdClass` to array;
* collection mapping;
* empty collection mapping;
* `toJson()`;
* representation classes are rejected by `to()`;
* definitions and Registry exports remain unchanged.

## Do not implement

* array-to-typed-DTO mapping;
* generic object-to-array mapping;
* attributes;
* dot notation;
* nested objects;
* PHPDoc collection types;
* field resolvers;
* `DefinitionRowMapper`;
* additional FieldTypes;
* Query integration;
* REST, database, relation, mutation, or ORM behavior;
* container/application discovery;
* separate mapper registry/provider/factory classes;
* `MappingKind`.

## Quality gate

Run:

```bash
composer validate --strict
composer dump-autoload
composer test
composer analyse
composer check-style
composer check
```

Commit Phase 2 separately and report:

* starting and final SHAs;
* files added and changed;
* public APIs;
* lazy-construction behavior;
* custom-constructor behavior;
* test and quality results;
* confirmation that Phase 3 was not started.
