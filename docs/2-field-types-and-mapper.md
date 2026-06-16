# Field Types and Mapper Runtime

`ON\Data` now includes the Phase 1 scalar conversion foundation plus the Phase 2 structural mapper runtime under `ON\Data\Mapper`.

The current mapper layer is intentionally shallow. It supports mapper registration and selection, collection mapping, and array/`stdClass` structural mapping, but it does not yet implement typed DTO hydration, nested object graphs, or definition-aware row mapping.

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

## MapperManager

Each `ConversionGateway` owns one `MapperManager`:

```php
$mappers = $gateway->getMappers();
```

The manager keeps mapper registration, selection, construction, and instance reuse in one place.

Registration is lazy:

- registering a mapper stores only the class string;
- registration order is preserved;
- duplicate registrations are rejected;
- a mapper is instantiated only when it is selected for a mapping operation;
- one instance is cached per mapper class;
- `clear()` drops mapper instances but keeps registrations;
- `warmUp()` eagerly constructs all registered mappers.

The default mapper set is intentionally small:

- `ON\Data\Mapper\ArrayToStdClassMapper`
- `ON\Data\Mapper\StdClassToArrayMapper`

Automatic selection uses each mapper's static `canMap()` method in registration order. Explicit `using()` selection skips unrelated mapper scans and validates that the chosen mapper can handle the requested operation.

## Mapper construction

Default construction for built-in and custom structural mappers is:

```php
new $mapper($gateway)
```

Custom construction can be installed before the first mapper instance is created:

```php
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MapperInterface;

$gateway->getMappers()->setConstructor(
    static function (string $mapper, ConversionGateway $runtime): MapperInterface {
        return new $mapper($runtime);
    },
);
```

The constructor callback receives the mapper class name and the active gateway. Once any mapper instance has been created, changing the constructor is rejected so the runtime stays consistent.

## MappingContext

`ON\Data\Mapper\MappingContext` carries immutable per-operation mapper state:

- the active `ConversionGateway`;
- source and output representation class names;
- an explicitly selected mapper class;
- mapper arguments;
- collection mode;
- the nested path currently being mapped.

Collection mapping and nested mapper calls retain the active gateway through the context.

## Fluent map()

Import the helper once:

```php
use function ON\Data\Mapper\map;
```

Map to a structural target:

```php
$user = map(['id' => 10, 'name' => 'Ada'])->to(stdClass::class);
```

Configure the source representation explicitly:

```php
use ON\Data\Mapper\Representation\WireRepresentation;

$builder = map($payload)
    ->from(WireRepresentation::class);
```

Set the output representation for downstream mappers:

```php
$builder = map($payload)
    ->as(WireRepresentation::class);
```

Select a mapper directly and pass mapper-specific arguments:

```php
$result = map($payload)
    ->using(CustomMapper::class, 'extra-option')
    ->to($target);
```

The fluent configuration methods return clones:

- `from()`
- `as()`
- `using()`
- `args()`
- `collection()`

Passing a representation class to `to()` is rejected. Use `as()` for representation selection and `to()` for structural targets.

## Collection mapping

Collection mode applies mapper selection item-by-item and returns a list:

```php
$rows = map(
    [
        ['id' => 1],
        ['id' => 2],
    ],
)->collection()->to(stdClass::class);
```

Empty iterables map to an empty list. Non-iterable sources are rejected when collection mode is enabled.

## toArray() and toJson()

The fluent builder includes convenience helpers:

```php
$array = map($stdClass)->toArray();
$json = map($stdClass)->toJson();
```

`toArray()` maps through the registered structural mappers when needed. `toJson()` encodes the resulting array as JSON and is useful for shallow export scenarios.

## Current shallow-mapper limitations

The built-in structural mappers are intentionally narrow:

- `ArrayToStdClassMapper` maps one array to one `stdClass`;
- `StdClassToArrayMapper` maps one `stdClass` to one array;
- both mappers are shallow only;
- nested DTO/object mapping is not implemented;
- attributes and custom property metadata are not implemented;
- FieldType inference is not used by the shallow structural mappers;
- generic object-to-array mapping beyond `stdClass` is not implemented;
- definition-aware row mapping is not implemented.

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

- typed DTO/object mapping
- nested object mapping
- generic object-to-array mapping beyond `stdClass`
- definition-row mapping
- attribute-driven mapping rules
