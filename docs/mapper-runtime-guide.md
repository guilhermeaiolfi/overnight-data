# Mapper Runtime Guide

`ON\Data` includes scalar conversion and recursive structural mapping under `ON\Data\Mapper`.

The mapper runtime supports arrays, `stdClass`, and public-property DTOs. It also supports definition-aware scalar conversion through `->args($definition)`, ad-hoc scalar metadata through `->fieldMap(...)`, dotted-key expansion, and representation-aware conversion routed through canonical PHP values.

## Core concepts

Representations are class-based markers implementing `ON\Data\Mapper\Representation\RepresentationInterface`.

- `PhpRepresentation` is the canonical hub.
- `StorageRepresentation` is the storage-facing marker.
- `WireRepresentation` is the external-input and JSON-facing marker and extends `StorageRepresentation`.

Field types own:

- their registered names
- their storage type hint
- default conversion to canonical PHP
- default conversion from canonical PHP

Codecs own one specific `FieldType + Representation` pair.

## Canonical conversion flow

Conversions between non-identical representations route through canonical PHP:

```text
source representation
    -> codec or field type toPhp()
    -> canonical PHP
    -> codec or field type fromPhp()
    -> target representation
```

Resolution order for each non-PHP side is:

```text
exact representation codec
nearest parent representation codec
field type default conversion
```

If the source and target representations are the same, the original value is returned unchanged. `null` always passes through unchanged.

## Built-in field types

Built-in handlers:

- `StringFieldType`
- `PassthroughFieldType`
- `BoolFieldType`
- `IntFieldType`
- `BigIntFieldType`
- `DecimalFieldType`
- `FloatFieldType`
- `DateFieldType`
- `BackedEnumFieldType`
- `DateTimeFieldType`

Default aliases:

```text
string       -> StringFieldType
text         -> PassthroughFieldType
bool         -> BoolFieldType
boolean      -> BoolFieldType
int          -> IntFieldType
integer      -> IntFieldType
primary      -> IntFieldType
smallprimary -> IntFieldType
bigint       -> BigIntFieldType
biginteger   -> BigIntFieldType
bigprimary   -> BigIntFieldType
decimal      -> DecimalFieldType
float        -> FloatFieldType
double       -> FloatFieldType
date         -> DateFieldType
datetime     -> DateTimeFieldType
timestamp    -> DateTimeFieldType
backed-enum  -> BackedEnumFieldType
```

Alias lookup is case-insensitive.

## Registration

`MapperManager` is the public registration facade for:

- mappers
- writers
- node resolvers
- field types
- field-type codecs

Register custom field types and codecs through the gateway-owned manager:

```php
use ON\Data\Mapper\ConversionGateway;

$gateway = ConversionGateway::createDefault();

$gateway
    ->getMapperManager()
    ->register(MoneyFieldType::class)
    ->register(MoneyWireCodec::class);
```

Custom representations do not need registration:

```php
use ON\Data\Mapper\Representation\WireRepresentation;

final class ApiRepresentation extends WireRepresentation
{
}
```

## Custom field type example

```php
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class MoneyFieldType implements FieldTypeInterface
{
    public static function getNames(): array
    {
        return ['money'];
    }

    public static function getStorageType(): string
    {
        return 'decimal';
    }

    public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
    {
        return (string) $value;
    }

    public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
    {
        return (string) $value;
    }
}
```

## Custom codec example

```php
use ON\Data\Mapper\FieldTypeCodecInterface;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class MoneyWireCodec implements FieldTypeCodecInterface
{
    public static function getFieldType(): string
    {
        return MoneyFieldType::class;
    }

    public static function getRepresentation(): string
    {
        return WireRepresentation::class;
    }

    public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
    {
        return $value;
    }

    public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
    {
        return $value;
    }
}
```

A codec registered for `WireRepresentation` automatically applies to child representations unless a more specific codec exists. Because `WireRepresentation` extends `StorageRepresentation`, a storage-oriented default can remain on the field type while a wire-only codec overrides just the wire-facing behavior.

## Conversion gateway

Create an empty gateway:

```php
$gateway = new ConversionGateway();
```

Create the built-in default gateway:

```php
$gateway = ConversionGateway::createDefault();
```

Convert values explicitly by naming the source and target representations:

```php
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolution;

$phpValue = $gateway->to(
    StorageRepresentation::class,
    '10',
    PhpRepresentation::class,
    LeafNodeResolution::named('id', 'int'),
);
```

## Runtime components

Each `ConversionGateway` owns one `MapperManager`:

```php
$runtime = $gateway->getMapperManager();
```

Default registered runtime components:

- mappers: `ArrayMapper`, `ObjectMapper`
- writers: `ArrayWriter`, `ObjectWriter`
- resolvers: `FieldMapNodeResolver`, `DefinitionNodeResolver`, `ReflectionPropertyNodeResolver`, `GenericNodeResolver`, `PassthroughNodeResolver`
- field types: the built-in scalar handlers listed above

Mappers and writers are cached reusable instances. Resolvers are constructed per mapping. Field types and codecs are static classes and are never instantiated.

## Fluent mapping API

Import the helper once:

```php
use function ON\Data\Mapper\map;
```

Examples:

```php
map($dto)->to([]);

map($payload)
    ->from(WireRepresentation::class)
    ->to(UserDto::class);

map($dto)
    ->as(WireRepresentation::class)
    ->to([]);
```

Override runtime components for one mapping:

```php
map($source)
    ->mapper(CustomMapper::class)
    ->resolver(CustomNodeResolver::class)
    ->writer(CustomWriter::class)
    ->args($definition)
    ->to([]);
```

## Mapping execution model

A mapper processes one source branch level at a time. It owns writer preparation, immediate child enumeration, node resolution, conversion, recursive branch dispatch, writing, and writer finalization. Concrete mappers only decide whether they can map a source value and how to enumerate that value's immediate children.

When a child resolves as a branch, recursive dispatch flows through `MapperManager::mapNode()`. Mapper selection therefore happens from the branch's runtime source value, which allows hybrid trees such as array root -> object child -> array grandchild.

## Object writer behavior

`ObjectWriter` supports eager and delayed object creation.

- `stdClass` targets and writable DTO targets without constructor requirements are created eagerly.
- Targets with constructor parameters, readonly public properties, or readonly classes are created lazily.
- While creation is delayed, resolved child values are buffered in `ObjectWriterState`.
- Constructor parameters are matched by resolved field or property name after resolver and attribute normalization.
- Values consumed by the constructor are not written again after instantiation.
- Remaining values are applied through the normal public-property write path.
- Missing required constructor parameters throw `MappingException`.
- Readonly targets force constructor-style creation when delayed instantiation is required.

## Definition-aware resolution

When one direct `DefinitionInterface` is supplied through `->args($definition)`, the default `DefinitionNodeResolver` can derive `LeafNodeResolution` values for fields and `BranchNodeResolution` values for relations. Definition collections describe metadata; runtime collection cardinality comes from relation cardinality, PHPDoc list metadata, or explicit root collection mapping.

## FieldMap support

`FieldMap` provides mapping-local scalar metadata without reintroducing the old blueprint subsystem:

```php
use ON\Data\Mapper\FieldMap;

map($payload)
    ->from(StorageRepresentation::class)
    ->fieldMap(FieldMap::fromArray([
        'id' => 'bigint',
        'amount' => 'decimal',
        'items.price' => 'decimal',
    ]))
    ->to([]);
```

`FieldMap::fromArray()` accepts either:

- `'path' => 'type'`
- `'path' => ['type' => 'type', 'nullable' => true]`

Configured paths are case-sensitive dotted paths. Numeric configured segments are rejected, but runtime numeric segments are ignored during lookup so `items.price` applies to `items.0.price`, `items.1.price`, and later list entries.

## Reflection fallback

`ReflectionPropertyNodeResolver` still infers primitive scalar properties and also infers:

- backed-enum property classes
- immutable-compatible datetime properties such as `DateTimeImmutable` and `DateTimeInterface`

Mutable concrete datetime properties such as `DateTime` are intentionally not inferred because the mapper's canonical datetime value is `DateTimeImmutable`.

## Resolver precedence

Default precedence is:

```text
custom resolver configured through ->resolver()
FieldMapNodeResolver
DefinitionNodeResolver
ReflectionPropertyNodeResolver
GenericNodeResolver
PassthroughNodeResolver
```

Definition metadata therefore still wins over reflection, while `FieldMap` wins over both for the paths it explicitly defines. `PassthroughNodeResolver` is the final fallback and preserves unchanged values when no metadata applies.

## Exact numeric behavior

`decimal` and `bigint` use normalized strings as their canonical PHP values.

- `decimal` accepts decimal strings and PHP integers, and rejects floats to avoid precision loss
- `bigint` accepts integer strings and PHP integers without coercing through platform-sized integers
- `bigprimary` is a field-type alias for mapper conversion only and does not affect collection primary-key metadata

## Current limitations

- no ORM or framework integration
- no complete PHPDoc parsing beyond the currently supported DTO list forms
- no decimal arithmetic, scale or precision policy, or bigint arithmetic helpers
