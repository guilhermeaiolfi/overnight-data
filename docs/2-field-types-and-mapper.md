# Field Types and Conversion Gateway

`ON\Data` now includes the Phase 1 scalar conversion foundation under `ON\Data\Mapper`.

This phase is intentionally limited to scalar conversion. Structural mapping, `MapBuilder`, `map()`, and mapper selection are deferred to later phases.

## Canonical representations

Conversions route through canonical PHP values.

- `ON\Data\Mapper\Representation\PhpRepresentation` is the canonical application representation.
- `ON\Data\Mapper\Representation\StorageRepresentation` is the storage-facing representation for database-driver-compatible scalar values.
- `ON\Data\Mapper\Representation\WireRepresentation` is the external-input and JSON-compatible representation.

Non-identical conversions route through PHP:

```text
Storage -> PHP -> Wire
Wire -> PHP -> Storage
```

If the source and target representations are the same, the original value is returned unchanged.

`null` always passes through unchanged.

## Field contexts

`ON\Data\Mapper\FieldContext` provides immutable per-field conversion context.

It can be created directly:

```php
use ON\Data\Mapper\FieldContext;

$field = FieldContext::named('id', 'int');
```

Or from a definition field:

```php
use ON\Data\Mapper\FieldContext;

$field = FieldContext::fromField($collection->getField('id'));
```

The context carries the field name, type, nullability flag, and the original field when available.

Phase 1 does not depend on copied field-definition metadata, so `FieldContext::fromField()` keeps an empty metadata array and preserves the live optional field reference through `getField()` and `hasField()`.

## Built-in FieldTypes

Phase 1 includes these built-ins:

- `StringFieldType`
- `PassthroughFieldType`
- `BoolFieldType`
- `IntFieldType`
- `FloatFieldType`

The default registry provides these aliases:

```text
string       -> StringFieldType
text         -> PassthroughFieldType
bool         -> BoolFieldType
boolean      -> BoolFieldType
int          -> IntFieldType
integer      -> IntFieldType
primary      -> IntFieldType
smallprimary -> IntFieldType
float        -> FloatFieldType
double       -> FloatFieldType
```

Deliberately deferred:

- `bigprimary`
- `decimal`
- dates and datetimes
- enums
- JSON and value objects

Those need explicit policy decisions before they can be added safely.

## Boolean input policy

`BoolFieldType` accepts only these forms:

- native booleans `true` and `false`
- integers `1` and `0`
- floats `1.0` and `0.0`
- strings `"1"` and `"0"`
- case-insensitive strings `"true"` and `"false"`
- case-insensitive strings `"yes"` and `"no"`
- case-insensitive strings `"on"` and `"off"`

String inputs are trimmed before matching, so surrounding whitespace is ignored.

Examples:

```text
" TRUE "  -> true
" off "   -> false
```

Ambiguous values such as `""`, `"2"`, `"-1"`, `"enabled"`, `"disabled"`, and `"maybe"` are rejected.

## Registry and gateway usage

Use `FieldTypeRegistry` to register custom handlers:

```php
use ON\Data\Mapper\FieldTypeRegistry;

$fieldTypes = FieldTypeRegistry::createDefault()
    ->register('money', MoneyFieldType::class);
```

Build a gateway explicitly:

```php
use ON\Data\Mapper\ConversionGateway;

$gateway = new ConversionGateway($fieldTypes);
```

Convert values by naming the source and target representations:

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

## Default runtime

`ON\Data\Mapper\Mapping` is the only ambient default holder.

Applications can install a configured gateway once:

```php
use ON\Data\Mapper\Mapping;

Mapping::setDefaultGateway($gateway);
```

And reset it in tests or isolated runtimes:

```php
Mapping::resetDefaultGateway();
```

If no gateway is installed, `Mapping::getDefaultGateway()` lazily creates and reuses a built-in default instance.

## Boundaries

The Phase 1 implementation does not depend on:

- Overnight framework bootstrap
- application singletons
- service containers
- PSR containers
- REST
- Cycle
- Doctrine
- ORM session or entity tracking

Definition arrays remain plain data. Conversion runtime objects are not stored in registries, collections, views, fields, relations, or exported definition arrays.

## Deferred mapper phases

The following are intentionally not implemented yet:

- `MapperInterface`
- `Mapper`
- `MapperManager`
- `MappingContext`
- `MapBuilder`
- `map()`
- array/object structural mappers
- definition-row mapping
