# Mapper Runtime Guide

`ON\Data` includes scalar conversion and recursive structural mapping under `ON\Data\Mapper`.

The mapper runtime supports arrays, `stdClass`, and public-property DTOs. It also supports definition-aware scalar conversion through `->args($definition)`, ad-hoc scalar metadata through `->fieldMap(...)`, dotted-key expansion, and representation-aware conversion routed through canonical PHP values. That conversion authority is shared step S2 in [`read-write.md`](./read-write.md).

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

`map()` uses `Mapping::getDefaultGateway()` when no gateway is passed. That default is a **process-wide static**: `Mapping::setDefaultGateway()` replaces it for the whole PHP process until `resetDefaultGateway()`.

In request-isolated PHP-FPM that is usually fine. In long-lived workers (RoadRunner, FrankenPHP workers, Swoole, shared CLI daemons), do not leave a request- or tenant-specific gateway as the ambient default—pass an explicit gateway into `map()` / runtime construction, or reset after the request so the next tenant does not inherit it.

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

`MapperManager::createDefault($gateway)` configures and returns that same manager. It adds missing built-in components without replacing the manager or recreating cached mappers and writers. Existing field type aliases and codecs retain precedence over defaults, including registrations made before default initialization. Later explicit registrations still override earlier ones. An optional component constructor is applied through `setConstructor()`, which still rejects changes after reusable components have been created; omitting it keeps the current constructor.

`canMap($source)` checks for a mapper that accepts the source. `canWrite($target)` checks for a writer that accepts the target. `supports($source, $target)` requires both capabilities. These checks use the same component order and optional `MappingOptions` overrides as mapping and return `false` when no component matches. They inspect capabilities without constructing components or validating individual field values.

Default registered runtime components:

- mappers: `ArrayMapper`, `ObjectMapper`
- writers: `ArrayWriter`, `ObjectWriter`
- resolvers: `FieldMapNodeResolver`, `DefinitionNodeResolver`, `ReflectionPropertyNodeResolver`, `GenericNodeResolver`, `PassthroughNodeResolver`
- field types: the built-in scalar handlers listed above

Registered mappers and writers are cached reusable instances. Explicit overrides that are not registered are constructed when selected. Resolvers are constructed as needed for each mapping operation, then their chains are reused within that operation, including recursive branches and collections. Field types and codecs are static classes and are never instantiated.

Ownership follows these lifetimes:

| Owner | Creates and retains |
|---|---|
| `MapperManager` | Mappers, writers, and resolvers through the configured component constructor; cached mapper/writer instances |
| `MappingRuntime` | One operation's resolver chains, collection resolution caches, and lazily created shared helpers |
| `MappingBranch` | One branch's output state, created by its writer |
| `ConversionGateway` | Scalar conversion routes through field types and codecs |

`MappingRuntime::getSharedInstance()` is the sole cache for operation-scoped helpers such as `BranchTargetInferrer`, `MappingNodePropertyFinder`, and `DefinitionArgumentLocator`. Built-in resolvers request those helpers from their current runtime without retaining extra copies of the references. Constructor-injected helper instances still take precedence. Keeping a resolver alive therefore does not retain helpers from a finished operation.

`MappingRuntime::convert()` owns mapping-specific conversion: it skips untyped values and mappings without representations, supplies the PHP representation for an unspecified side, and adds the failing node path to conversion errors. It delegates scalar conversion to the manager's gateway (`MapperManager::getGateway()`). `FieldConversionCoordinator` and `MapperManager::createFieldConversionCoordinator()` have been removed.

The component constructor hook still receives the requested component class and the same gateway. Registration and prepend order are unchanged. The shared-helper cache constructs its own helper instances and does not use that hook.

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

Mapper argument options (same `->args(...)` channel):

```php
use ON\Data\Mapper\Mapper\ArrayMapperOptions;
use ON\Data\Mapper\Mapper\ObjectMapperOptions;

// Disable dotted-key expansion for array sources.
map($row)->args(new ArrayMapperOptions(expandDottedKeys: false))->to([]);

// Remap nested concrete objects under stdClass (off by default).
map($row)->args(new ObjectMapperOptions(convertNestedObjects: true))->to(stdClass::class);
```

By default, `map(...)->to(stdClass::class)` still converts the **root** value into `stdClass`, and nested arrays / nested `stdClass` still recurse. Already-concrete nested objects (for example a `FileDescriptor`) stay as themselves unless `ObjectMapperOptions(convertNestedObjects: true)` is passed.

### Shallow mapping

```php
$properties = map($object, gateway: $gateway)->shallow()->to([]);
$dto = map($row)->shallow()->to(UserDto::class);
```

`shallow()` maps immediate properties or entries to the requested target. Nested arrays and objects pass through as supplied, including cyclic values. The same mode works for array, `stdClass`, and DTO targets. For DTOs, nested values must already fit the target property or constructor parameter types. Top-level field names, resolver rules, and leaf conversions still apply. With `collection()`, each item is mapped shallowly.

Object property names containing dots stay literal. For array sources, `ArrayMapper` still expands dotted keys at the level it maps, including merging overlapping paths. Pass `ArrayMapperOptions(expandDottedKeys: false)` through `args()` to keep those keys literal. Dotted keys inside untouched nested arrays stay literal.

Use `shallow(false)` to restore recursive mapping on a new builder. Direct callers of `MapperManager::map()` can set the same option with `MappingOptions::withShallow()`.

## Mapping execution model

A mapper processes one source branch level at a time. Concrete mappers decide whether they can map a source value, enumerate its immediate children, and pass each child to `MappingBranch::write()`. The branch owns writer state, node resolution, writing, and finalization; the runtime supplies conversion and recursive dispatch.

When a child resolves as a branch, recursive dispatch flows through `MappingRuntime::mapNode()`. Mapper selection therefore happens from the branch's runtime source value, which allows hybrid trees such as array root -> object child -> array grandchild.

Recursive traversal throws `MappingException` with the current path when it revisits an object already on that path or exceeds 128 branch levels below the root. The depth limit also stops recursive PHP array references when the mapper follows them. Dotted-key expansion limits keys to 128 segments and bounds recursive merges to 128 levels from the merge root. These expansion limits also apply in shallow mode when expansion is enabled.

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

Use `#[MapCollection(ItemDto::class)]` to declare the item type of a public array or iterable property, including a readonly promoted constructor property:

```php
use ON\Data\Mapper\Attribute\MapCollection;
use function ON\Data\Mapper\map;

final readonly class PostDto
{
    public function __construct(
        #[MapCollection(AuthorDto::class)]
        public array $authors,
    ) {}
}

$post = map($row)->to(PostDto::class);
```

By default, the attribute takes precedence over property `@var` and constructor `@param` list types. When the attribute supplies the item class, the mapper skips PHPDoc parsing and source-file scanning. Existing PHPDoc list types continue to work when the attribute is absent.

### Collection item type extractors

The native PHP property type and the collection item class answer separate questions. For `#[MapCollection(AuthorDto::class)] public ?array $authors`, PHP supplies the nullable array type and the attribute supplies `AuthorDto`. The mapper uses both when mapping a value. A DTO query uses the item class to decide which fields and relations to select.

The default order is `MapCollectionAttributeExtractor` followed by `PhpDocListItemTypeExtractor`. Set `listItemTypeExtractors:` on a gateway to supply an ordered replacement. Each extractor implements `ListItemTypeExtractorInterface` and receives a `ReflectionProperty`. Return a class name to answer the lookup or `null` to let the next extractor try.

```php
use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Metadata\CachedListItemTypeExtractor;
use ON\Data\Mapper\Metadata\MapCollectionAttributeExtractor;
use ON\Data\Mapper\Metadata\PhpDocListItemTypeExtractor;

$gateway = ConversionGateway::createDefault(listItemTypeExtractors: [
    new MapCollectionAttributeExtractor(),
    new CachedListItemTypeExtractor(new PhpDocListItemTypeExtractor()),
]);

$post = map($row, gateway: $gateway)->to(PostDto::class);
```

By default, item type results are cached for one mapping or one query projection. The explicit decorator above reuses successful PHPDoc lookups for as long as that decorator is kept. Its default limit is 256 properties, with the oldest entry evicted when full. Make the gateway local to a job to release the decorator when the job releases the gateway. Pass the same gateway when creating a SQL runtime if you want DTO queries to use that extractor list for both projection and hydration.

## Definition-aware resolution

When one direct `DefinitionInterface` is supplied through `->args($definition)`, the default `DefinitionNodeResolver` can derive `LeafNodeResolution` values for fields and `BranchNodeResolution` values for relations. Runtime collection cardinality can come from the relation definition, `#[MapCollection]` or PHPDoc list metadata, or an explicit root `collection()` call.

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

- framework container wiring is supplied by the host application
- no complete PHPDoc parsing beyond the currently supported DTO list forms
- no decimal arithmetic, scale or precision policy, or bigint arithmetic helpers
