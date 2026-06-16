Implement Mapper Phase 3A from:

```text
5a1d5ecfd48980603e39b5614cc128db44c79232
```

## Goal

Add typed public-property object mapping:

```php
map($array)->to(UserDto::class);
map($dto)->toArray();
```

Keep this phase shallow. Do not implement nested DTO graphs, PHPDoc list mapping, or definition-row mapping yet.

## Add

* `ArrayToObjectMapper`
* `ObjectToArrayMapper`
* `MapFrom` property attribute
* `MapTo` property attribute
* `Hidden` property attribute
* tests and documentation

Register the new mappers in `MapperManager::createDefault()` without changing the existing lazy construction and caching model.

Keep the existing `stdClass` mappers more specific:

* `ArrayToStdClassMapper` handles `stdClass`;
* `StdClassToArrayMapper` handles `stdClass`;
* `ArrayToObjectMapper` excludes `stdClass`;
* `ObjectToArrayMapper` excludes `stdClass`.

## ArrayToObjectMapper

Support concrete classes with public instance properties.

Required behavior:

* accept an array source and a concrete class-string target;
* instantiate without requiring constructor arguments;
* do not invoke the target constructor;
* map public instance properties only;
* ignore static and non-public properties;
* ignore unknown source keys;
* leave a property unchanged when its source key is absent;
* preserve declared default property values;
* use `MapFrom` when present;
* use the property name otherwise;
* distinguish a missing key from a present `null`;
* wrap assignment and reflection failures in `MappingException`;
* include the class and property path in errors.

Do not support interfaces, abstract classes, enums, or representation classes as structural targets.

Readonly-property and constructor hydration are deferred. Fail clearly rather than partially hydrating an unsupported target.

## ObjectToArrayMapper

Required behavior:

* accept a non-`stdClass` object and an array target;
* read public instance properties only;
* ignore static and non-public properties;
* skip uninitialized properties;
* omit properties marked `Hidden`;
* use `MapTo` when present;
* use the property name otherwise;
* preserve explicit `null` values;
* return a plain array.

Do not serialize methods, getters, private state, or constructor parameters.

Exclude scalar-like objects such as `DateTimeInterface` and `BackedEnum`; those belong to FieldType conversion, not structural object mapping.

## Attributes

Use property-only, non-repeatable attributes.

Suggested APIs:

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class MapFrom
{
    public function __construct(
        private string $name,
    );

    public function getName(): string;
}
```

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class MapTo
{
    public function __construct(
        private string $name,
    );

    public function getName(): string;
}
```

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Hidden
{
}
```

Reject empty `MapFrom` and `MapTo` names.

`Hidden` affects outbound object-to-array mapping only.

Do not add class-level mapping attributes.

## Primitive representation conversion

When mapping an array to an object and a source representation is configured:

```php
map($payload)
    ->from(WireRepresentation::class)
    ->to(InputDto::class);
```

derive a `FieldContext` from each supported public property and convert the value to canonical PHP through the existing `ConversionGateway`.

Support these declared primitive property types:

* `string`
* `int`
* `bool`
* `float`

Use the property name and nullability in the generated `FieldContext`.

When mapping an object to an array and an output representation is configured:

```php
map($dto)
    ->as(WireRepresentation::class)
    ->toArray();
```

convert supported primitive properties from `PhpRepresentation` to the configured output representation.

Rules:

* no representation configured: perform structural mapping only;
* untyped or `mixed` property: leave the value unchanged;
* supported nullable primitive: preserve `null`;
* union and intersection conversion are deferred;
* class-typed properties are not recursively mapped in this phase;
* do not invent a field-resolver pipeline.

Use `MappingContext::withPathSegment()` for property-level errors.

## Mapper defaults

Do not force representation conversion by default.

`defaultRepresentations()` for these mappers should not cause ordinary:

```php
map($array)->to(UserDto::class);
```

to reinterpret scalar values unexpectedly.

Conversion occurs only when `from()` or `as()` declares a representation boundary.

## Required tests

Cover:

* array to typed object;
* object to array;
* constructor is not called;
* public inherited properties;
* static properties ignored;
* private and protected properties ignored;
* unknown input keys ignored;
* missing keys preserve defaults;
* missing keys leave uninitialized properties uninitialized;
* explicit nullable `null`;
* invalid non-nullable assignment produces `MappingException`;
* `MapFrom`;
* `MapTo`;
* `Hidden`;
* uninitialized outbound property skipped;
* primitive conversion from wire representation;
* primitive conversion to wire representation;
* conversion failure includes the nested property path;
* collection mapping to DTOs through the existing `collection()` mode;
* collection mapping from DTOs to arrays;
* existing `stdClass` mappings remain unchanged;
* mapper construction remains lazy;
* selected mapper instances remain cached;
* abstract, interface, enum, representation, and readonly targets fail clearly.

## Do not implement

* nested object mapping;
* lists of typed objects;
* PHPDoc parsing;
* dot-path expansion;
* constructor-argument hydration;
* setters;
* private-property mutation;
* readonly-object hydration;
* arbitrary class/value-object FieldTypes;
* field resolver or conversion coordinator subsystems;
* `DefinitionRowMapper`;
* additional FieldTypes;
* Query integration;
* database, REST, mutation, relation, or ORM behavior.

Do not add speculative abstractions for the deferred features.

## Documentation

Update:

* `README.md`
* `docs/2-field-types-and-mapper.md`

Document:

* public-property DTO mapping;
* constructor bypass;
* `MapFrom`, `MapTo`, and `Hidden`;
* explicit representation-aware primitive conversion;
* current shallow limitations.

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

Commit Phase 3A separately and report:

* starting and final SHAs;
* files added and changed;
* public APIs;
* mapper registration order;
* constructor-bypass behavior;
* attribute behavior;
* representation-conversion behavior;
* test and quality results;
* confirmation that nested mapping and Phase 3B were not started.
