# ON\Data — FieldType, Representations, and Mapper Milestone

Repository:

```text
https://github.com/guilhermeaiolfi/overnight-data
```

Required baseline:

```text
a7b219957e4297275a6853ef84c43cf168a0408f
Finalize definition migration
```

## Execution instruction

This document defines the complete FieldType, Representations, and Mapper milestone.

Implement **Phase 1 only** in the first Codex task.

Do not automatically begin Phase 2 or any later phase. End Phase 1 with a completion report and wait for review.

---

# 1. Purpose

Add the standalone conversion and structural-mapping foundation for `ON\Data`.

The completed milestone will provide:

* FieldType-based scalar conversion;
* canonical PHP, storage, and wire representations;
* a conversion gateway;
* application-configurable default mapping behavior;
* the existing fluent `map($source)->to(...)` model;
* lazy structural mapper creation and caching;
* generic array/object mapping;
* shallow definition-aware row conversion.

This milestone must remain independent from:

* the Overnight framework;
* the Overnight application singleton;
* the Overnight container;
* PSR containers;
* REST;
* Cycle Database;
* Doctrine DBAL;
* ORM entity tracking.

---

# 2. Mandatory inspection before implementation

Before changing code, inspect:

* the exact current Git SHA;
* Git history;
* `README.md`;
* all architecture documents under `docs/`;
* all existing tests;
* all public definition interfaces;
* current field classes and their public metadata;
* current `Collection::getKey()`;
* current `Collection::getKeyFromRecord()`;
* current `ON\Data\Key`.

Also inspect the current Overnight implementation of:

* `FieldTypeInterface`;
* `FieldTypeRegistry`;
* all built-in FieldTypes;
* `FieldContext`;
* `RepresentationInterface`;
* `PhpRepresentation`;
* `StorageRepresentation`;
* `WireRepresentation`;
* `ConversionGateway`;
* `MapBuilder`;
* `MappingContext`;
* `MapperInterface`;
* `MapperRegistry`;
* the `map()` function;
* array/object structural mappers;
* mapper attributes;
* field resolvers;
* `CollectionRowMapper`;
* mapper tests and documentation.

Reuse tested behavior where it matches this specification.

Do not copy framework bootstrap, application lookup, container lookup, REST behavior, or old ORM dependencies.

---

# 3. Definition architecture is closed

Do not reopen or redesign the definition system.

Preserve these established rules:

* Registry owns one canonical plain-array definition tree.
* Registry owns collections and views.
* Collections and views own fields and relations.
* Definition owners create final stored slots before wrappers.
* Definition wrappers bind directly to final stored slots.
* Stored nodes cannot be created as orphans and attached later.
* Node names are immutable runtime context.
* Owner map keys are canonical node names.
* Stored arrays do not duplicate contextual node names.
* `DefinitionFactory` is stateless.
* Wrapper caches are owner-local.
* Stored definition data remains plain data.
* Reads do not mutate definitions.
* Definitions export and restore without runtime services.
* Custom nodes use the inherited constructor.
* Custom defaults use `definitionDefaults()`.
* Runtime-only initialization uses `initializeRuntimeState()`.
* Fluent definitions and `->end()` remain intact.
* Composite primary keys remain first-class.

Do not store any conversion or mapping runtime object in:

* Registry;
* Collection definitions;
* View definitions;
* Field definitions;
* Relation definitions;
* exported definition arrays.

---

# 4. Fundamental separation

The implementation must keep two responsibilities distinct.

## 4.1 Scalar conversion

A FieldType converts one value between representations.

Examples:

```text
storage "10" -> PHP 10
wire "false" -> PHP false
PHP DateTimeImmutable -> wire string
PHP enum -> storage scalar
```

Scalar conversion uses:

* `FieldTypeInterface`;
* `FieldTypeRegistry`;
* `FieldContext`;
* representation classes;
* `ConversionGateway`.

## 4.2 Structural mapping

A structural mapper changes the shape containing values.

Examples:

```text
array -> DTO
DTO -> array
iterable<array> -> list<DTO>
nested array -> nested DTO
```

Structural mapping uses:

* `MapperInterface`;
* `Mapper`;
* `MapperManager`;
* `MappingContext`;
* `MapBuilder`;
* `map()`.

FieldType remains the value-conversion authority.

Structural mappers must not introduce a second scalar type system.

---

# 5. Namespace and source layout

All production code remains under:

```php
ON\Data
```

Recommended layout:

```text
src/
  Mapper/
    Attribute/
    Exception/
    Field/
      Handler/
    Representation/
    Structural/
    ConversionGateway.php
    Mapping.php
    MapBuilder.php
    MappingContext.php
    Mapper.php
    MapperInterface.php
    MapperManager.php
    functions.php
```

Recommended namespaces:

```php
ON\Data\Mapper
ON\Data\Mapper\Attribute
ON\Data\Mapper\Exception
ON\Data\Mapper\Field
ON\Data\Mapper\Field\Handler
ON\Data\Mapper\Representation
ON\Data\Mapper\Structural
```

Do not introduce another package root such as `ON\Mapper`.

---

# 6. Final architectural decisions

## 6.1 ConversionGateway is an ordinary runtime object

`ConversionGateway` must not:

* discover an application;
* inspect `Application::$instance`;
* inspect a service container;
* depend on `ContainerInterface`;
* maintain its own static singleton;
* read framework configuration;
* register itself into a framework.

It receives or constructs its dependencies explicitly.

## 6.2 `map()` has a configured default runtime

Normal application code must remain:

```php
map($source)->to(Target::class);
```

Applications configure their custom gateway once during bootstrap:

```php
Mapping::setDefaultGateway($gateway);
```

The `map()` helper uses:

1. the explicitly passed gateway, when supplied;
2. otherwise the gateway installed through `Mapping`;
3. otherwise one lazily created built-in gateway.

Passing a gateway on every `map()` call is not the normal application path.

## 6.3 MapperManager is one cohesive component

Do not create separate:

* mapper registry;
* mapper provider;
* mapper factory;
* mapper service locator.

`MapperManager` owns all closely related mapper-runtime work:

* class registration;
* registration order;
* mapper selection;
* lazy construction;
* constructed-instance caching;
* explicit mapper selection;
* optional warm-up;
* cache clearing.

## 6.4 No MappingKind

Do not introduce:

* `MappingKind`;
* route enums;
* route indexes;
* source/target category registration;
* mapper-resolution caches.

The initial mapper list is small.

Selection uses each registered mapper’s static `canMap()` method.

Only add indexing or resolution caching after profiling proves it is necessary and a correct cache key can be defined.

## 6.5 Default mappers share one constructor

Default structural mappers receive the active gateway:

```php
public function __construct(
    protected readonly ConversionGateway $gateway,
);
```

Provide this through an abstract base class:

```php
abstract class Mapper implements MapperInterface
{
    public function __construct(
        protected readonly ConversionGateway $gateway,
    ) {
    }
}
```

The constructor must not be declared on `MapperInterface`.

Custom mappers may use different constructors when the application supplies a custom mapper-construction closure.

## 6.6 Mapper classes are registered; instances are lazy

`MapperManager` stores mapper class strings.

It does not eagerly create mapper objects.

Resolution sequence:

```text
registered mapper classes
    ↓ static canMap()
selected mapper class
    ↓ lazy construction
cached mapper instance
    ↓ map()
```

Unused mappers are never instantiated.

---

# 7. FieldType contract

Use the existing FieldType conversion model:

```php
interface FieldTypeInterface
{
    /**
     * Logical storage family only.
     *
     * This must not return or expose a Cycle or Doctrine type object.
     */
    public static function storageType(): string;

    /**
     * Convert from the named representation to canonical PHP.
     *
     * @param class-string<RepresentationInterface> $from
     */
    public static function toPhp(
        string $from,
        mixed $value,
        FieldContext $field,
    ): mixed;

    /**
     * Convert canonical PHP to the named representation.
     *
     * @param class-string<RepresentationInterface> $to
     */
    public static function fromPhp(
        string $to,
        mixed $value,
        FieldContext $field,
    ): mixed;
}
```

`storageType()` is a logical hint.

It must not:

* compile SQL;
* select a database platform type;
* depend on a DBAL;
* quote values;
* bind parameters.

Database-specific type mapping belongs to later adapter packages.

---

# 8. FieldContext

Provide an immutable conversion context.

Suggested API:

```php
final readonly class FieldContext
{
    /**
     * @param class-string<FieldTypeInterface>|non-empty-string $type
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $name,
        string $type,
        bool $nullable = false,
        ?FieldInterface $field = null,
        array $metadata = [],
    );

    public static function fromField(
        FieldInterface $field,
    ): self;

    /**
     * @param class-string<FieldTypeInterface>|non-empty-string $type
     * @param array<string, mixed> $metadata
     */
    public static function named(
        string $name,
        string $type,
        bool $nullable = false,
        array $metadata = [],
    ): self;

    public function getName(): string;

    public function getType(): string;

    public function isNullable(): bool;

    public function hasField(): bool;

    public function getField(): ?FieldInterface;

    public function getMetadata(
        string $key,
        mixed $default = null,
    ): mixed;

    public function isClassType(): bool;
}
```

Use `getSomething()` naming.

Do not retain an old Overnight ORM field interface.

`FieldContext` is not:

* stored metadata;
* a second field definition;
* a service locator;
* mutable mapping state.

## Null handling

Conversion and validation remain separate responsibilities.

Required gateway behavior:

```text
null input -> null output
```

Do not reject `null` merely because `FieldContext::isNullable()` is false.

Required/non-null validation belongs to validation or mutation boundaries.

FieldType handlers may inspect nullability when a specific conversion policy needs it, but the gateway must preserve `null` by default.

---

# 9. FieldTypeRegistry

Provide deterministic registration and lookup.

Suggested API:

```php
final class FieldTypeRegistry
{
    public static function createDefault(): self;

    /**
     * @param class-string<FieldTypeInterface> $handler
     */
    public function register(
        string $type,
        string $handler,
    ): self;

    public function has(string $type): bool;

    /**
     * @return class-string<FieldTypeInterface>
     */
    public function get(string $type): string;

    /**
     * @return class-string<FieldTypeInterface>|null
     */
    public function resolve(
        FieldContext $field,
    ): ?string;
}
```

Resolution order:

1. A field type class that implements `FieldTypeInterface`.
2. An explicitly registered exact key.
3. A normalized lowercase alias.
4. A supported special class family such as backed enums or dates.
5. Otherwise no FieldType is resolved.

Registration must validate that the handler implements `FieldTypeInterface`.

Do not automatically treat every existing PHP class as a scalar FieldType.

Generic value-object conversion through magic methods is deferred.

---

# 10. Representations

Provide:

```php
interface RepresentationInterface
{
    public function toPhp(
        mixed $value,
        FieldContext $field,
    ): mixed;

    public function fromPhp(
        mixed $value,
        FieldContext $field,
    ): mixed;
}
```

Built-in representations:

```php
PhpRepresentation
StorageRepresentation
WireRepresentation
```

Meaning:

```text
PhpRepresentation
    Canonical application values.

StorageRepresentation
    Database-driver-compatible scalar values.

WireRepresentation
    External-input and JSON-compatible scalar values.
```

Canonical routing:

```text
same representation
    -> return unchanged

source representation
    -> canonical PHP
    -> target representation
```

For example:

```text
Storage -> Wire
Storage -> PHP -> Wire
```

Do not add an edge-converter graph in this milestone.

Do not port `EdgeConverterRegistry` unless a concrete required conversion cannot be represented correctly through canonical PHP.

---

# 11. ConversionGateway

Suggested shape:

```php
final class ConversionGateway
{
    private readonly MapperManager $mappers;

    /**
     * @param null|Closure(
     *     class-string<MapperInterface>,
     *     ConversionGateway
     * ): MapperInterface $mapperConstructor
     */
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        ?Closure $mapperConstructor = null,
        RepresentationInterface ...$representations,
    ) {
        $this->mappers = MapperManager::createDefault(
            gateway: $this,
            constructor: $mapperConstructor,
        );

        // Register representations.
    }

    public static function createDefault(): self;

    public function registerRepresentation(
        RepresentationInterface $representation,
    ): self;

    public function getFieldTypes(): FieldTypeRegistry;

    public function getMappers(): MapperManager;

    /**
     * @param class-string<RepresentationInterface> $from
     * @param class-string<RepresentationInterface> $to
     */
    public function to(
        string $from,
        mixed $value,
        string $to,
        FieldContext $field,
    ): mixed;
}
```

Required behavior:

* return `null` unchanged;
* return the original value when source and target representations are equal;
* reject unknown representation classes;
* resolve the field handler through `FieldTypeRegistry`;
* route non-identical conversions through canonical PHP;
* preserve the original exception as the previous exception;
* report field name, type, source representation, and target representation in conversion errors.

Do not add:

```php
ConversionGateway::get()
ConversionGateway::setInstance()
ConversionGateway::configure()
ConversionGateway::tryContainer()
```

The gateway itself has no static global lifecycle.

---

# 12. Mapping default runtime

Provide one narrow static facade for the convenience `map()` function:

```php
final class Mapping
{
    private static ?ConversionGateway $defaultGateway = null;

    public static function setDefaultGateway(
        ConversionGateway $gateway,
    ): void {
        self::$defaultGateway = $gateway;
    }

    public static function getDefaultGateway(): ConversionGateway
    {
        return self::$defaultGateway
            ??= ConversionGateway::createDefault();
    }

    public static function resetDefaultGateway(): void
    {
        self::$defaultGateway = null;
    }
}
```

There must be only one ambient default.

Do not add separate static defaults to:

* `FieldTypeRegistry`;
* `MapperManager`;
* representation classes;
* `ConversionGateway`.

Application bootstrap:

```php
$fieldTypes = FieldTypeRegistry::createDefault()
    ->register('money', MoneyFieldType::class)
    ->register('uuid', UuidFieldType::class);

$gateway = new ConversionGateway(
    fieldTypes: $fieldTypes,
    mapperConstructor: static function (
        string $mapper,
        ConversionGateway $gateway,
    ) use ($objectFactory): MapperInterface {
        return $objectFactory->make(
            $mapper,
            ['gateway' => $gateway],
        );
    },
    ...$representations,
);

$gateway
    ->getMappers()
    ->register(MoneyMapper::class)
    ->register(ProductMapper::class);

Mapping::setDefaultGateway($gateway);
```

Normal application code:

```php
$product = map($payload)
    ->from(WireRepresentation::class)
    ->to(ProductInput::class);
```

An explicit gateway remains an operation-level override:

```php
$product = map(
    $payload,
    gateway: $specialGateway,
)->to(ProductInput::class);
```

This supports tests and multiple independent mapping environments without making the normal API verbose.

---

# 13. MapperInterface and shared Mapper base

Suggested interface:

```php
interface MapperInterface
{
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

    /**
     * @return array{
     *     from?: class-string<RepresentationInterface>,
     *     as?: class-string<RepresentationInterface>
     * }
     */
    public static function defaultRepresentations(): array;
}
```

Shared constructor:

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

Default structural mappers extend `Mapper`.

Custom mappers may implement `MapperInterface` directly.

---

# 14. MapperManager

`MapperManager` replaces the separate registry/provider/factory design.

Suggested API:

```php
final class MapperManager
{
    /**
     * @var list<class-string<MapperInterface>>
     */
    private array $mappers = [];

    /**
     * @var array<class-string<MapperInterface>, MapperInterface>
     */
    private array $instances = [];

    /**
     * @param null|Closure(
     *     class-string<MapperInterface>,
     *     ConversionGateway
     * ): MapperInterface $constructor
     */
    public function __construct(
        private readonly ConversionGateway $gateway,
        private readonly ?Closure $constructor = null,
    ) {
    }

    public static function createDefault(
        ConversionGateway $gateway,
        ?Closure $constructor = null,
    ): self;

    /**
     * @param class-string<MapperInterface> $mapper
     */
    public function register(string $mapper): self;

    /**
     * Preserve only if required by current public behavior or tests.
     *
     * @param class-string<MapperInterface> $mapper
     */
    public function replace(string $mapper): self;

    public function has(string $mapper): bool;

    /**
     * @return list<class-string<MapperInterface>>
     */
    public function getRegisteredMappers(): array;

    public function map(
        mixed $source,
        mixed $target,
        MappingContext $context,
    ): mixed;

    /**
     * @param class-string<MapperInterface> $mapper
     */
    public function getMapper(string $mapper): MapperInterface;

    public function warmUp(): void;

    public function clear(): void;
}
```

## Registration

`register()`:

* validates the class;
* requires `MapperInterface`;
* stores only the class string;
* preserves registration order;
* rejects duplicate registration;
* does not instantiate the mapper.

## Selection

When no mapper is explicitly selected:

```php
foreach ($this->mappers as $mapper) {
    if ($mapper::canMap($source, $target, $context)) {
        return $this
            ->getMapper($mapper)
            ->map($source, $target, $context);
    }
}
```

When `using()` selects a mapper:

* confirm it is registered;
* confirm `canMap()` accepts the operation;
* create only that mapper;
* do not scan unrelated classes.

## Construction

Default construction:

```php
new $mapper($this->gateway);
```

Custom construction:

```php
($this->constructor)(
    $mapper,
    $this->gateway,
);
```

Validate the constructed result.

## Cache

Cache constructed instances by mapper class.

`clear()` removes instances but preserves registered classes.

`warmUp()` deliberately instantiates all registered classes.

Normal operation remains lazy.

Do not add:

* `MapperRegistry`;
* `MapperProvider`;
* `MapperFactoryInterface`;
* container lookup;
* application lookup;
* `MappingKind`;
* route indexing.

---

# 15. MappingContext

Provide immutable per-operation state.

Suggested API:

```php
final readonly class MappingContext
{
    /**
     * @param class-string<RepresentationInterface>|null $sourceRepresentation
     * @param class-string<RepresentationInterface>|null $outputRepresentation
     * @param class-string<MapperInterface>|null $mapperClass
     * @param list<mixed> $arguments
     */
    public function __construct(
        ConversionGateway $gateway,
        ?string $sourceRepresentation = null,
        ?string $outputRepresentation = null,
        ?string $mapperClass = null,
        array $arguments = [],
        bool $collection = false,
        string $path = '',
    );

    public function getGateway(): ConversionGateway;

    public function getSourceRepresentation(): ?string;

    public function getOutputRepresentation(): ?string;

    public function getMapperClass(): ?string;

    public function getArguments(): array;

    public function isCollection(): bool;

    public function getPath(): string;

    public function withSourceRepresentation(
        ?string $representation,
    ): self;

    public function withOutputRepresentation(
        ?string $representation,
    ): self;

    public function withMapperClass(
        ?string $mapper,
        array $arguments = [],
    ): self;

    public function asCollection(): self;

    public function withPathSegment(string $segment): self;
}
```

`MappingContext` may carry the active gateway.

It must not contain:

* Query state;
* REST state;
* mutation state;
* entity tracking;
* relation loading;
* database connections.

---

# 16. MapBuilder and `map()`

Preserve the fluent API:

```php
use function ON\Data\Mapper\map;
```

Suggested helper:

```php
function map(
    mixed $source,
    ?string $from = null,
    ?ConversionGateway $gateway = null,
): MapBuilder {
    return new MapBuilder(
        source: $source,
        gateway: $gateway ?? Mapping::getDefaultGateway(),
        sourceRepresentation: $from,
    );
}
```

Required builder operations:

```php
map($source)->to(TargetDto::class);

map($source)
    ->from(WireRepresentation::class)
    ->to(TargetDto::class);

map($source)
    ->as(WireRepresentation::class)
    ->toArray();

map($rows)
    ->collection()
    ->to(TargetDto::class);

map($source)
    ->using(CustomMapper::class, ...$arguments)
    ->to($target);

map($source)->toArray();

map($source)->toJson();
```

Builder configuration methods must return clones:

* `from()`;
* `as()`;
* `using()`;
* `args()`;
* `collection()`.

`to()` is reserved for structural targets.

Passing a representation class to `to()` must fail with an error directing the caller to `as()`.

Nested mapping must retain the same gateway.

---

# 17. Built-in FieldType policy

Implement built-ins incrementally.

## Phase 1 types

Implement only:

* `StringFieldType`;
* `PassthroughFieldType`;
* `BoolFieldType`;
* `IntFieldType`;
* `FloatFieldType`.

Initial aliases:

```text
string          -> StringFieldType
text            -> PassthroughFieldType
bool, boolean   -> BoolFieldType
int, integer    -> IntFieldType
primary         -> IntFieldType
smallprimary    -> IntFieldType
float, double   -> FloatFieldType
```

Do not initially register:

```text
bigprimary -> IntFieldType
decimal -> FloatFieldType
```

Those aliases can silently lose range or precision.

## Later milestone types

Evaluate and implement separately:

* big integer;
* decimal;
* JSON;
* date;
* datetime;
* timestamp;
* backed enum;
* URL;
* explicit value objects.

Required policies for big integer and decimal must be settled before registering aliases.

Do not preserve an unsafe legacy alias solely for compatibility.

---

# 18. Structural mappers

Later structural phases should provide:

* array to object;
* array to `stdClass`;
* object to array;
* `stdClass` to array;
* iterable collection mapping;
* nested DTO mapping;
* shallow definition-row mapping.

Do not port:

* `PsrRequestToObjectMapper`;
* PSR request dependencies;
* REST mutation mapping;
* automatic parent back-reference wiring;
* old ORM relation handling;
* entity tracking.

## Property policy

The first structural implementation uses public properties.

Support:

* missing keys;
* nullable properties;
* default property values;
* nested declared object types;
* explicit mapper attributes;
* documented simple list PHPDoc forms.

Defer:

* constructor hydration;
* private-property mutation;
* setter discovery;
* arbitrary method invocation;
* full PHPDoc parsing.

---

# 19. DefinitionRowMapper

The final phase of this milestone adds a shallow definition-aware mapper.

Tentative name:

```php
DefinitionRowMapper
```

It operates with the shared definition contract so it can support:

* collections;
* structural views.

Required behavior:

* receive canonical definition field names as row keys;
* convert fields present in the row;
* leave missing fields absent;
* leave unknown selection aliases unchanged;
* preserve input row ordering;
* convert primary-key fields like ordinary fields;
* support custom FieldTypes;
* support one row or an iterable of rows;
* perform shallow conversion only.

It must not:

* translate raw database column names;
* recursively traverse relations;
* load relations;
* attach relations;
* parse REST mutation actions;
* execute queries;
* handle entity state;
* remove unknown computed selections.

Future query compilers should alias database columns to canonical result field names.

---

# 20. Existing Key boundary

Do not change during this milestone:

```php
CollectionInterface::getKey()
CollectionInterface::getKeyFromRecord()
Key
```

Do not inject a gateway into:

* Collection;
* Key;
* Registry;
* definitions.

Do not add a hidden global conversion lookup to Key creation.

FieldType-backed external key normalization belongs at later runtime boundaries:

* `Query::whereKey()`;
* `DataManager::find()`;
* mutation builders;
* ORM Session lookup.

Those boundaries normalize components before using the existing collection Key API.

---

# 21. Exceptions

Add focused exceptions under:

```php
ON\Data\Mapper\Exception
```

At minimum:

* `ConversionException`;
* `UnsupportedConversionException`;
* `FieldTypeNotFoundException`;
* `InvalidFieldTypeException`;
* `MapperNotFoundException`;
* `InvalidMapperException`;
* `MappingException`.

Relevant errors should include:

* field name;
* field type;
* source representation;
* target representation;
* source structural type;
* target structural type;
* nested mapping path;
* mapper class.

Preserve original exceptions as previous exceptions.

---

# 22. Phase plan

## Phase 1 — Scalar conversion foundation

Implement in the first Codex task:

* namespaces and directories;
* conversion exceptions;
* `FieldTypeInterface`;
* `FieldContext`;
* `FieldTypeRegistry`;
* `RepresentationInterface`;
* `PhpRepresentation`;
* `StorageRepresentation`;
* `WireRepresentation`;
* primitive FieldTypes;
* `ConversionGateway`;
* `Mapping`;
* built-in default gateway creation;
* explicit application gateway installation;
* architecture dependency tests;
* focused documentation.

Phase 1 does **not** implement structural mapping yet.

`ConversionGateway` may introduce the minimum `MapperManager` integration point needed by its final constructor design, but do not implement structural mappers, `MapBuilder`, or `map()` until Phase 2.

### Phase 1 acceptance tests

1. Default registry resolves primitive aliases.
2. Direct FieldType class references resolve.
3. Custom FieldType registration works.
4. Invalid FieldType registration fails.
5. Unknown FieldType resolution is explicit.
6. Storage integer string converts to PHP integer.
7. Wire integer string converts to PHP integer.
8. Supported boolean inputs convert deterministically.
9. Ambiguous boolean strings fail.
10. PHP values convert to storage.
11. PHP values convert to wire.
12. Same-representation conversion returns the original value.
13. `null` passes through unchanged.
14. Unknown representations fail.
15. Conversion errors retain previous exceptions.
16. `Mapping::getDefaultGateway()` reuses one built-in instance.
17. `Mapping::setDefaultGateway()` replaces the runtime used by the default holder.
18. `Mapping::resetDefaultGateway()` restores lazy built-in behavior.
19. Conversion does not mutate Registry definitions.
20. Restored definitions behave identically.
21. Production code contains no framework, container, ORM, REST, Cycle, Doctrine, or PSR HTTP dependency.

Stop after Phase 1.

## Phase 2 — Mapper runtime and fluent entry point

Implement only after review:

* `MapperInterface`;
* abstract `Mapper`;
* `MapperManager`;
* lazy mapper construction;
* mapper instance caching;
* custom mapper-construction closure;
* `MappingContext`;
* `MapBuilder`;
* `map()`;
* one minimal structural mapper pair proving the runtime;
* default-gateway use by `map()`;
* explicit gateway override.

Stop after Phase 2.

## Phase 3 — Structural mappers

Implement only after review:

* array to object;
* object to array;
* array to `stdClass`;
* `stdClass` to array;
* iterable collection mapping;
* attributes such as `MapFrom`, `MapTo`, and `Hidden`;
* dot notation using existing `ON\Data\Support\Dot`;
* nested DTO mapping;
* documented nested-list forms;
* representation-aware property conversion.

Stop after Phase 3.

## Phase 4 — Additional FieldTypes

Implement only after review:

* big integer;
* decimal;
* JSON;
* date;
* datetime;
* timestamp;
* backed enum;
* any approved URL or value-object handling.

Document deliberate differences from Overnight.

Stop after Phase 4.

## Phase 5 — Definition-row integration

Implement only after review:

* `DefinitionRowMapper`;
* collection fields;
* structural view fields;
* custom field classes;
* one-row and iterable modes;
* unknown alias preservation;
* Registry round-trip tests.

Stop after Phase 5.

---

# 23. Explicit non-goals

Do not implement in this milestone:

* query references;
* expression AST;
* fluent Query;
* QuerySpec;
* semantic view-field sources;
* aggregates;
* database adapters;
* SQL compilation;
* schema generation;
* query execution;
* relation loading;
* `with()`;
* direct mutations;
* mutation plans;
* `ValueRef`;
* relation mutation handlers;
* automatic Query mapping;
* entity identity maps;
* Session;
* Unit of Work;
* REST integration;
* admin integration;
* GraphQL integration;
* framework extensions.

Do not create abstractions for those later phases now.

---

# 24. Architecture checks

Add tests or static checks ensuring production code does not depend on:

```text
ON\Application
ON\Container
ON\ORM
ON\RestApi
Cycle
Doctrine
Psr\Http
Psr\Container
```

Also verify:

* definitions contain no runtime services;
* Registry exports remain plain data;
* no application lookup exists;
* no container lookup exists;
* no static gateway singleton exists inside `ConversionGateway`;
* `Mapping` is the only ambient default holder;
* mapper instances are not eagerly created;
* no `MappingKind` exists;
* no separate mapper provider/factory/registry classes exist;
* no later query or ORM classes are introduced.

---

# 25. Documentation

Add:

```text
docs/2-field-types-and-mapper.md
```

During Phase 1, document only implemented scalar behavior:

* canonical PHP representation;
* storage representation;
* wire representation;
* routing through PHP;
* primitive FieldTypes;
* custom FieldType registration;
* explicit gateway construction;
* configuring the default gateway through `Mapping`;
* absence of framework/container dependencies;
* deferred mapper phases;
* deferred big-integer and decimal policies.

Update `README.md` status accurately.

Do not document APIs before they exist.

---

# 26. Quality commands

Run after Phase 1:

```text
composer validate --strict
composer dump-autoload
composer test
composer analyse
composer check-style
composer check
```

Report the actual result of each command.

Do not claim a command succeeded when it was not run.

---

# 27. Phase 1 completion report

Report:

1. Exact starting commit SHA.
2. Exact final commit SHA, when committed.
3. Files added.
4. Files changed.
5. Public APIs introduced.
6. Overnight behavior reused.
7. Overnight behavior intentionally redesigned.
8. Overnight behavior rejected.
9. Deferred decisions.
10. Test results.
11. Static-analysis results.
12. Style-check results.
13. Architecture-check results.
14. Confirmation that Phase 2 was not started.

Stop after the report.
