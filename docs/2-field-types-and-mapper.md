# Field Types and Mapper Runtime

`ON\Data` includes the scalar conversion foundation plus a recursive composable mapper runtime under `ON\Data\Mapper`.

The mapper layer supports input-driven traversal of arrays, `stdClass`, and public-property DTOs, plus recursive structural mapping, definition-aware scalar conversion through `->args($definition)`, and default dotted-key expansion for flat rows. It still does not implement constructor hydration, readonly hydration, ORM integration, or framework-specific runtime behavior.

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

The context carries only the field name, type, and nullability flag.

`FieldContext::fromField()` reads a definition field and copies only those three conversion facts. It does not retain the original `FieldInterface` wrapper or a generic metadata snapshot.

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
  -> Walker, including recursive traversal decisions
  -> Resolver chain
  -> Representation conversion
  -> Writer
  -> Output
```

Responsibilities:

- walker: enumerate source nodes, own collection traversal, and initiate recursive mapping
- resolver: inspect the current leaf node and return a `FieldContext` when capable
- conversion: move scalar values between representations only when a boundary was declared
- writer: prepare the target and perform only the final per-node assignment

Traversal is input-driven. The walker determines what nodes exist, owns collection iteration, and can recurse into nested structures. The writer never traverses the source, and the target does not cause extra source traversal.

## Built-in components

Default registered order:

- walkers: `ArrayWalker`, `ObjectWalker`
- writers: `ArrayWriter`, `ObjectWriter`
- resolvers: `DefinitionFieldResolver`, `ReflectionPropertyFieldResolver`

This supports the built-in recursive matrix:

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

Nested typed properties and PHPDoc lists also work:

```php
final class PostDto
{
    public AuthorDto $author;

    /** @var list<AuthorDto> */
    public array $authors = [];
}
```

## Construction and registration

Registration is lazy:

- registering a component stores only its class string
- `prepend()` inserts a component at the front of its own role bucket
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

Prepended components win first-match automatic selection only within their own role bucket. Prepending a walker does not reorder writers or resolvers, and vice versa.

Explicit per-call fluent resolvers still run before every registered default resolver.

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

`MappingContext` is mapping-wide configuration only. It no longer stores traversal path, current source, prepared target, parent scope, or cycle state.

## MappingNode

`ON\Data\Mapper\MappingNode` is the current traversal frame.

It carries:

- the current node name, with the root using no name
- the current source value
- the requested or prepared structural target for that node
- the active `MappingContext`
- the parent node when one exists
- the effective mapping arguments
- the current collection mode
- walker-provided source `ReflectionProperty` evidence when available

Derived data such as path, parent source, parent target, and ancestor-chain cycle inspection now comes from the node tree rather than `MappingContext`.

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
Custom traversal behavior belongs in a custom walker rather than a second per-node extension chain.

## Definition-aware scalar conversion

When one direct `DefinitionInterface` is supplied through `args()`, the default `DefinitionFieldResolver` can resolve field metadata for structurally untyped sources such as arrays and request-style payloads:

```php
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Representation\StorageRepresentation;
use function ON\Data\Mapper\map;

$registry = new Registry();
$users = $registry->collection('users');
$users->field('id', 'int');
$users->field('active', 'bool');
$users->field('rating', 'float');

$result = map([
    'id' => '42',
    'active' => '0',
    'rating' => '19.5',
])
    ->from(StorageRepresentation::class)
    ->args($users)
    ->to([]);
```

Result:

```php
[
    'id' => 42,
    'active' => false,
    'rating' => 19.5,
]
```

Collection mode reuses the same mapping arguments for each top-level item, and nested relation scopes replace only the active definition while preserving unrelated arguments such as `ArrayWalkerOptions`:

```php
$rows = map([
    ['id' => '1', 'active' => '1'],
    ['id' => '2', 'active' => '0'],
])
    ->from(StorageRepresentation::class)
    ->args($users)
    ->collection()
    ->to([]);
```

`DefinitionFieldResolver` only uses exact effective field names emitted by the active walker. It does not resolve aliases, columns, or registry-wide lookups by itself.

The root `Registry` is not searched automatically. Pass the active definition itself:

```php
->args($registry->getDefinition('users'))
```

If mapping arguments contain more than one direct `DefinitionInterface`, resolution is ambiguous and the mapper throws a `MappingException` instead of choosing one arbitrarily.

Definition metadata wins over reflection because `DefinitionFieldResolver` runs before `ReflectionPropertyFieldResolver`. If the supplied definition does not contain the current field, it returns `null` and reflection remains a fallback for typed DTO properties.

Definition relations participate in recursive mapping. When the active definition has a matching relation name, child mapping uses the relation target definition for that nested scope and removes the parent definition from the child argument list.
Relation metadata only triggers recursion for structural values. Scalar identifiers such as `2`, `'2'`, and `null` stay on the normal field-conversion and write path.

Removed convenience APIs:

- `using()`
- `toArray()`

`to([])` is the canonical array destination.

## Dotted-key expansion

`ArrayWalker` expands dotted source keys before node creation by default, regardless of the destination type:

```php
$result = map([
    'id' => 1,
    'author.name' => 'Guilherme',
    'author.id' => 2,
])->to([]);
```

Result:

```php
[
    'id' => 1,
    'author' => [
        'name' => 'Guilherme',
        'id' => 2,
    ],
]
```

This makes flat joined rows and request payloads map cleanly into nested arrays, `stdClass`, and DTOs.

Disable expansion explicitly when literal dotted keys are required:

```php
use ON\Data\Mapper\Walker\ArrayWalkerOptions;

$result = map([
    'metadata.version' => '1.0',
])
    ->args(new ArrayWalkerOptions(false))
    ->to([]);
```

Malformed paths, scalar/branch collisions, and duplicate leaf collisions throw `MappingException`.

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

Inbound and outbound object support is recursive and public-property-based.

Inbound rules:

- concrete class-string targets are instantiated without invoking their constructors
- only public instance properties are considered
- static, private, and protected properties are ignored
- unknown input names are ignored for typed targets
- missing fields preserve defaults and uninitialized properties
- `MapFrom` is applied on the target side
- class-typed target properties recurse into nested DTO mapping
- PHPDoc list forms `Type[]`, `list<Type>`, and `array<Type>` enable typed nested DTO lists
- destination reflection metadata is authoritative for typed object targets
- readonly targets are rejected in this phase

Outbound rules:

- only public instance properties are exported
- static properties are ignored
- uninitialized typed properties are skipped
- explicit `null` values are preserved
- `MapTo` is applied on the source side
- `Hidden` omits a property from outbound object walking
- nested objects recurse into nested arrays, `stdClass`, or typed DTOs when structural evidence exists
- source reflection remains available when the destination is untyped, such as `[]` or `stdClass`

For typed collections, items that are already instances of the declared destination element class are preserved as-is. Mixed lists still hydrate structural items such as arrays into new destination DTO instances.

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

## Parent and cycle context

`MappingNode` now exposes or derives:

- `getValue()`
- `getTarget()`
- `getParent()`
- `getPath()`
- `getParentSource()`
- `getParentTarget()`

Writers and resolver helpers can inspect both the current and parent scope through the node tree. Parent object targets are the same live object instance; parent array targets are value snapshots only.

Recursive mapping also includes mapping-local object cycle protection. Re-encountering the same source object in the current ancestor chain throws `MappingException` with the current path. Sibling branches may reuse the same object without being treated as a cycle.

## Representation-aware primitive conversion

Structural mapping is representation-neutral by default:

```php
map(['id' => '10'])->to(UserDto::class);
```

Primitive conversion happens only when `from()` or `as()` creates a representation boundary.

Supplying a definition alone does not trigger conversion:

```php
map(['id' => '10'])
    ->args($users)
    ->to([]);
```

That call preserves the raw string value because no representation boundary was declared.

Supported reflection-derived primitive types in this phase:

- `string`
- `int`
- `bool`
- `float`

Untyped, `mixed`, union, intersection, and class-typed structural properties are left unchanged.

## Current limitations

The built-in runtime is intentionally narrow:

- no complete PHPDoc parsing beyond `Type[]`, `list<Type>`, and `array<Type>`
- no constructor-argument hydration
- no setter hydration
- no private-property mutation
- no readonly hydration
- no alias-name remapping
- no column-name remapping
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
