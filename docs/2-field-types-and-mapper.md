# Field Types and Mapper Runtime

`ON\Data` includes the scalar conversion foundation plus a shallow composable mapper runtime under `ON\Data\Mapper`.

The mapper layer is intentionally shallow. It supports input-driven traversal of arrays, `stdClass`, and public-property DTOs, but it does not implement nested object graphs, typed nested lists, definition-aware row mapping, ORM integration, or framework-specific runtime behavior.

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

`FieldContext::fromField()` keeps an empty metadata array in the current implementation and preserves the live optional field reference through `getField()` and `hasField()`.

## Built-in FieldTypes

Built-in handlers:

- `StringFieldType`
- `PassthroughFieldType`
- `BoolFieldType`
- `IntFieldType`
- `FloatFieldType`

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
float        -> FloatFieldType
double       -> FloatFieldType
```

Deliberately deferred:

- `bigprimary`
- `decimal`
- dates and datetimes
- enums
- JSON and value objects

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

## Composable mapper runtime

Each `ConversionGateway` owns one `MapperManager`:

```php
$runtime = $gateway->getMappers();
```

The runtime is composed from independent component roles:

```text
Input
  -> Walker
  -> Resolver chain
  -> Representation conversion
  -> Writer
  -> Output
```

Responsibilities:

- walker: enumerate available source fields and expose walker-specific context
- resolver: inspect the current field evidence and return a `FieldContext` when capable
- conversion: move scalar values between representations only when a boundary was declared
- writer: prepare the target and perform the final per-field assignment

Traversal is input-driven. The walker determines what fields exist. The writer does not traverse the source, and the target does not cause extra source traversal.

## Built-in components

Default registered order:

- walkers: `ArrayWalker`, `ObjectWalker`
- writers: `ArrayWriter`, `ObjectWriter`
- resolvers: `ReflectionPropertyFieldResolver`

This supports the built-in shallow matrix:

```php
map($array)->to([]);
map($array)->to(stdClass::class);
map($array)->to(UserDto::class);

map($stdClass)->to([]);
map($stdClass)->to(stdClass::class);
map($stdClass)->to(UserDto::class);

map($dto)->to([]);
map($dto)->to(stdClass::class);
map($dto)->to(AnotherDto::class);
```

## Construction and registration

Registration is lazy:

- registering a component stores only its class string
- registration order is preserved within each role bucket
- duplicate registrations are rejected
- walkers and writers are instantiated only when selected
- one instance is cached per selected walker class and per selected writer class
- resolvers are instantiated fresh for each mapping
- `clear()` drops reusable walker and writer instances but preserves registrations
- `warmUp()` eagerly constructs registered walkers and writers

Applications may install a custom constructor callback before reusable instances exist:

```php
$gateway->getMappers()->setConstructor(
    static function (string $component, ConversionGateway $runtime): object {
        return new $component();
    },
);
```

## MappingContext

`ON\Data\Mapper\MappingContext` carries immutable per-operation state:

- the active `ConversionGateway`
- source representation
- output representation
- an explicit walker class
- an explicit writer class
- explicit resolver classes
- mapping arguments
- collection mode
- current path
- prepared runtime target

## Fluent map()

Import the helper once:

```php
use function ON\Data\Mapper\map;
```

Map to the canonical array target:

```php
$data = map($dto)->to([]);
```

Map to `stdClass`:

```php
$object = map(['id' => 10, 'name' => 'Ada'])->to(stdClass::class);
```

Map to a shallow DTO:

```php
$user = map(['id' => 10, 'name' => 'Ada'])->to(UserDto::class);
```

Configure the source representation explicitly:

```php
use ON\Data\Mapper\Representation\WireRepresentation;

$user = map($payload)
    ->from(WireRepresentation::class)
    ->to(UserDto::class);
```

Configure the output representation:

```php
$wire = map($dto)
    ->as(WireRepresentation::class)
    ->to([]);
```

Override components for one mapping:

```php
$result = map($source)
    ->walker(CustomWalker::class)
    ->resolver(CustomFieldResolver::class)
    ->writer(CustomWriter::class)
    ->args($customEvidence)
    ->to([]);
```

The fluent configuration methods return clones:

- `from()`
- `as()`
- `walker()`
- `writer()`
- `resolver()`
- `args()`
- `collection()`

Passing a representation class to `to()` is rejected. Use `as()` for representation selection and `to()` for structural targets.

Removed convenience APIs:

- `using()`
- `toArray()`

`to([])` is the canonical array destination.

## Collection mapping

Collection mode applies the same runtime item by item and returns a list:

```php
$rows = map([
    ['id' => 1],
    ['id' => 2],
])->collection()->to(UserDto::class);
```

Empty iterables map to an empty list. Non-iterable sources are rejected when collection mode is enabled.

## Public-property DTO mapping

Inbound and outbound object support is shallow and public-property-based.

Inbound rules:

- concrete class-string targets are instantiated without invoking their constructors
- only public instance properties are considered
- static, private, and protected properties are ignored
- unknown input names are ignored for typed targets
- missing fields preserve defaults and uninitialized properties
- `MapFrom` is applied on the target side
- readonly targets are rejected in this phase

Outbound rules:

- only public instance properties are exported
- static properties are ignored
- uninitialized typed properties are skipped
- explicit `null` values are preserved
- `MapTo` is applied on the source side
- `Hidden` omits a property from outbound object walking

Combined aliasing works across object-to-object mapping:

```php
final class SourcePost
{
    #[MapTo('post_title')]
    public string $title;
}

final class TargetPost
{
    #[MapFrom('post_title')]
    public string $heading;
}

$result = map($source)->to(TargetPost::class);
```

## Representation-aware primitive conversion

Structural mapping is representation-neutral by default:

```php
map(['id' => '10'])->to(UserDto::class);
```

Primitive conversion happens only when `from()` or `as()` creates a representation boundary.

Supported reflection-derived primitive types in this phase:

- `string`
- `int`
- `bool`
- `float`

Untyped, `mixed`, union, intersection, and class-typed structural properties are left unchanged.

## Current shallow limitations

The built-in runtime is intentionally narrow:

- no nested DTO graphs
- no recursive object mapping into class-typed properties
- no typed nested collections
- no PHPDoc parsing
- no constructor-argument hydration
- no setter hydration
- no private-property mutation
- no readonly hydration
- no dot-path expansion
- no definition-aware row mapping
- no ORM, REST, or framework integration

## Boundaries

The implementation does not depend on:

- Overnight framework bootstrap
- application singletons
- service containers
- PSR containers
- REST
- Cycle
- Doctrine
- ORM session or entity tracking

Definition arrays remain plain data. Conversion runtime objects are not stored in registries, collections, views, fields, relations, or exported definition arrays.
