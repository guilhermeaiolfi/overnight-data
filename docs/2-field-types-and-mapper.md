# Field Types and Mapper Runtime

`ON\Data` includes scalar conversion and recursive structural mapping under `ON\Data\Mapper`.

The mapper layer supports arrays, `stdClass`, and public-property DTOs, definition-aware scalar conversion through `->args($definition)`, ad-hoc scalar metadata through `->fieldMap(...)`, dotted-key expansion, and representation-aware conversion routed through canonical PHP values.

## Core roles

Representations are class-based markers implementing `ON\Data\Mapper\Representation\RepresentationInterface`.

- `PhpRepresentation` is the canonical hub.
- `StorageRepresentation` is the storage-facing marker.
- `WireRepresentation` is the external-input and JSON-facing marker and now extends `StorageRepresentation`.

Field types own:

- their registered names;
- their storage type hint;
- default conversion to canonical PHP;
- default conversion from canonical PHP.

Codecs own one specific `FieldType + Representation` pair.

## Canonical conversion path

Non-identical representation conversions route through PHP:

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

If source and target representations are equal, the original value is returned unchanged. `null` always passes through unchanged.

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

`MapperManager` is the single public registration facade for:

- walkers;
- writers;
- field resolvers;
- field types;
- field-type codecs.

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
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Representation\WireRepresentation;

class ApiRepresentation extends WireRepresentation
{
}
```

## Field type example

```php
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

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

    public static function toPhp(mixed $value, FieldContext $field): mixed
    {
        return (string) $value;
    }

    public static function fromPhp(mixed $value, FieldContext $field): mixed
    {
        return (string) $value;
    }
}
```

## Codec example

```php
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeCodecInterface;
use ON\Data\Mapper\Representation\WireRepresentation;

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

    public static function toPhp(mixed $value, FieldContext $field): mixed
    {
        return $value;
    }

    public static function fromPhp(mixed $value, FieldContext $field): mixed
    {
        return $value;
    }
}
```

A codec registered for `WireRepresentation` automatically applies to child representations unless a more specific codec exists. Because `WireRepresentation` extends `StorageRepresentation`, a storage-oriented default can stay on the field type while a wire-only codec overrides just the wire-facing behavior.

## ConversionGateway

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
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;

$phpValue = $gateway->to(
    StorageRepresentation::class,
    '10',
    PhpRepresentation::class,
    FieldContext::named('id', 'int'),
);
```

## Mapper runtime

Each `ConversionGateway` owns one `MapperManager`:

```php
$runtime = $gateway->getMapperManager();
```

Default registered runtime components:

- walkers: `ArrayWalker`, `ObjectWalker`
- writers: `ArrayWriter`, `ObjectWriter`
- resolvers: `FieldMapFieldResolver`, `DefinitionFieldResolver`, `ReflectionPropertyFieldResolver`
- field types: the built-in scalar handlers above

Walkers and writers are cached reusable instances. Resolvers are constructed per mapping. Field types and codecs are static classes and are never instantiated.

## Fluent mapping

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
    ->walker(CustomWalker::class)
    ->resolver(CustomFieldResolver::class)
    ->writer(CustomWriter::class)
    ->args($definition)
    ->to([]);
```

## Definition-aware scalar conversion

When one direct `DefinitionInterface` is supplied through `->args($definition)`, the default `DefinitionFieldResolver` can derive `FieldContext` values for untyped structural sources such as arrays and request payloads.

## FieldMap

`FieldMap` adds small mapping-local scalar metadata without recreating the old blueprint subsystem:

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

Configured paths are case-sensitive dotted paths. Numeric configured segments are rejected, but runtime numeric segments are ignored during lookup so `items.price` applies to `items.0.price`, `items.1.price`, and so on.

## Reflection fallback

`ReflectionPropertyFieldResolver` still infers primitive scalar properties and now also infers:

- backed-enum property classes
- immutable-compatible datetime properties such as `DateTimeImmutable` and `DateTimeInterface`

Mutable concrete datetime properties such as `DateTime` are intentionally not inferred because the mapper's canonical datetime value is `DateTimeImmutable`.

## Resolver precedence

Default precedence is:

```text
custom resolver configured through ->resolver()
FieldMapFieldResolver
DefinitionFieldResolver
ReflectionPropertyFieldResolver
```

Definition metadata therefore still wins over reflection, while `FieldMap` wins over both for the paths it explicitly defines.

## Exact numeric field types

`decimal` and `bigint` use normalized strings as their canonical PHP values.

- `decimal` accepts decimal strings and PHP integers, and rejects floats to avoid precision loss
- `bigint` accepts integer strings and PHP integers without coercing through platform-sized integers
- `bigprimary` is only a field-type alias for mapper conversion and does not affect collection primary-key metadata

## Current limitations

- no constructor hydration
- no readonly-target hydration
- no ORM or framework integration
- no complete PHPDoc parsing beyond the currently supported DTO list forms
- no decimal arithmetic, scale/precision policy, or bigint arithmetic helpers
