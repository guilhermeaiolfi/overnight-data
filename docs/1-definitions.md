# Step 1 Specification: Extract the Overnight Definition System into `ON\Data`

## 1. Objective

Create a new standalone repository containing the existing Overnight data-definition system under the namespace:

```php
ON\Data
```

This step is an extraction and structural migration, not a redesign.

Use the definitions currently located in:

```text
overnight
└── src/ORM/Definition
```

from the `cycle-mutation` branch as the authoritative source.

The result must preserve the existing definition DSL and metadata while introducing exactly these architectural changes:

1. Store the complete Registry definition in one master array, following the same general model used by Overnight’s `ViewConfig`.
2. Replace `PrimaryKeyDefinition` and `PrimaryKeyValue` with one simpler `Key` class and collection-owned primary-key metadata.
3. Move `primaryKey()` from `Field` to `Collection`.
4. Add `ViewDefinition` as the definition root for future application/business models.

Do not implement querying, mapping execution, relation loading, persistence, Unit of Work, or REST integration in this step.

---

# 2. Repository and package identity

## 2.1 Suggested repository name

```text
overnight-data
```

## 2.2 Suggested Composer package name

Use one of these according to the available Composer/GitHub organization:

```json
{
    "name": "guilhermeaiolfi/overnight-data"
}
```

or, if an `overnight` Composer organization is available:

```json
{
    "name": "overnight/data"
}
```

This naming decision must not affect the PHP namespace.

## 2.3 PSR-4 namespace

```json
{
    "autoload": {
        "psr-4": {
            "ON\\Data\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\ON\\Data\\": "tests/"
        }
    }
}
```

Use the same minimum PHP version currently supported by Overnight unless a migrated class strictly requires a newer version.

Do not require:

```text
cycle/orm
doctrine/orm
guilhermeaiolfi/overnight
```

The definition package must be standalone.

A database abstraction will be selected in a later step.

---

# 3. Scope of the migration

## 3.1 Migrate the complete current definition subsystem

Migrate all classes currently under:

```text
src/ORM/Definition/
├── Collection/
├── Display/
├── Exception/
├── Field/
├── Interface/
├── Metadata/
├── Relation/
├── Schema/
├── MetadataTrait.php
└── Registry.php
```

Preserve:

* existing public configuration methods;
* existing getter methods;
* existing method arguments;
* existing defaults, except for adapter-specific defaults explicitly identified below;
* existing metadata keys;
* existing display definitions;
* existing interface definitions;
* existing schema metadata;
* existing field metadata;
* existing relation metadata;
* existing relation subclasses;
* `FieldMap`;
* `RelationMap`;
* `MetadataTrait`;
* `DisplayTrait`;
* `InterfaceTrait`;
* `SchemaTrait`;
* `->end()` chaining;
* file-definition-location tracking.

## 3.2 Namespace migration

Perform this mechanical namespace migration:

```text
ON\ORM\Definition
    ↓
ON\Data\Definition
```

Examples:

```text
ON\ORM\Definition\Registry
    ↓
ON\Data\Definition\Registry
```

```text
ON\ORM\Definition\Collection\Collection
    ↓
ON\Data\Definition\Collection\Collection
```

```text
ON\ORM\Definition\Relation\HasManyRelation
    ↓
ON\Data\Definition\Relation\HasManyRelation
```

The new `Key` class belongs at:

```php
ON\Data\Key
```

It is not placed under `ORM`, `REST`, or `Collection`, because later queries, mutations, caches, relation handlers, and the ORM will all use it.

The new view classes belong under:

```text
ON\Data\Definition\View
```

---

# 4. Explicit non-goals

Do not implement or migrate the following in this step:

* `DataManager`;
* query objects;
* `QuerySpec`;
* `FieldRef`;
* `ValueRef`;
* expressions;
* aggregates;
* query compilation;
* relation handlers;
* relation loading;
* SQL generation;
* Cycle Database integration;
* Doctrine DBAL integration;
* Mapper execution;
* `map($query)->to(...)`;
* `FieldType` execution;
* Representations;
* persistence operations;
* mutation planning;
* Unit of Work;
* identity map;
* entity mapping;
* REST API integration;
* GraphQL integration;
* query caching;
* entity caching;
* schema migrations;
* generated entity classes.

The existing field type name and mapper-related metadata must still be preserved in the definition arrays, but their runtime systems are not migrated in this step.

Do not add temporary replacements for these systems.

---

# 5. Preserve the existing fluent definition style

The new package must continue supporting the current one-chain definition style:

```php
$registry
    ->collection('users')
        ->table('users')
        ->primaryKey('id')

        ->field('id', 'integer')
            ->column('id')
            ->autoIncrement(true)
            ->end()

        ->field('name', 'string')
            ->required(true)
            ->searchable()
            ->end()

        ->field('email', 'string')
            ->filterable()
            ->end()

        ->hasMany('posts', 'posts')
            ->innerKey('id')
            ->outerKey('user_id')
            ->end()
        ->end();
```

Composite primary key:

```php
$registry
    ->collection('post_user')
        ->table('post_user')
        ->primaryKey('post_id', 'user_id')

        ->field('post_id', 'integer')
            ->required(true)
            ->end()

        ->field('user_id', 'integer')
            ->required(true)
            ->end()

        ->field('created_at', 'datetime')
            ->end()

        ->belongsTo('post', 'posts')
            ->innerKey('post_id')
            ->outerKey('id')
            ->end()

        ->belongsTo('user', 'users')
            ->innerKey('user_id')
            ->outerKey('id')
            ->end()
        ->end();
```

Do not:

* remove `end()`;
* forward parent methods through `Field`;
* forward parent methods through `Relation`;
* replicate collection methods in child builders;
* replace the DSL with callbacks;
* replace the DSL with detached definition objects;
* rename existing configuration methods without an explicit requirement in this specification.

---

# 6. One Registry-owned master array

## 6.1 Current problem

The current Registry owns an array of `Collection` objects.

Each `Collection` independently owns:

* PHP properties;
* a `FieldMap` containing `Field` objects;
* a `RelationMap` containing `Relation` objects.

That means the full definition is distributed across an object graph.

The new implementation must have one plain master array as the complete source of truth.

## 6.2 Registry storage

`Registry` must own the complete definition:

```php
final class Registry
{
    /**
     * Complete source of truth.
     *
     * @var array{
     *     collections: array<string, array<string, mixed>>,
     *     views: array<string, array<string, mixed>>
     * }
     */
    protected array $items;
}
```

Conceptual structure:

```php
[
    'collections' => [
        'users' => [
            'name' => 'users',
            'table' => 'users',
            'database' => 'default',
            'primaryKey' => ['id'],

            // Preserve the remaining current collection data.
            'entity' => stdClass::class,
            'scope' => null,
            'repository' => null,
            'mapper' => null,
            'source' => null,
            'parentCollection' => null,
            'note' => null,
            'description' => null,
            'hidden' => false,
            'fileLocation' => null,
            'metadata' => [],

            'fields' => [
                'id' => [
                    'class' => Field::class,
                    'name' => 'id',
                    'type' => 'integer',
                    'column' => 'id',

                    // Preserve all existing Field data.
                ],
            ],

            'relations' => [
                'posts' => [
                    'class' => HasManyRelation::class,
                    'name' => 'posts',
                    'collectionName' => 'posts',

                    // Preserve all existing relation data.
                ],
            ],
        ],
    ],

    'views' => [
        'user_summary' => [
            'name' => 'user_summary',
            'source' => 'users',
            'metadata' => [],
            'fields' => [],
            'relations' => [],
        ],
    ],
]
```

This example is illustrative.

During implementation, use the current property and metadata names as the array keys. Do not rename all stored keys merely to make them stylistically consistent.

The new keys introduced by this step are:

```text
collections
views
primaryKey
class
```

## 6.3 Plain-data requirement

The result of:

```php
$registry->all();
```

must recursively contain only values suitable for ordinary array caching:

* arrays;
* strings;
* integers;
* floats;
* booleans;
* null;
* class strings;
* existing cache-safe callable-array definitions.

It must not contain:

* `Collection` objects;
* `Field` objects;
* `Relation` objects;
* `FieldMap` objects;
* `RelationMap` objects;
* display objects;
* interface objects;
* Registry references;
* parent references;
* Cycle objects;
* Doctrine objects.

Closures must not be introduced into the master array in this step.

## 6.4 Construction from cached data

The Registry constructor must accept an existing definition array:

```php
$registry = new Registry($cachedDefinition);
```

The following round trip must preserve the definition:

```php
$first = new Registry();

$first
    ->collection('users')
        ->primaryKey('id')
        ->field('id', 'integer')
            ->end()
        ->end();

$array = $first->all();

$second = new Registry($array);

assert($second->all() === $array);
```

All typed accessors must work after restoration.

---

# 7. Config-style node implementation

## 7.1 Internal support

The new package must not depend on the complete Overnight framework merely to use `ON\Config\Config`.

Extract only the small generic configuration behavior required for:

* `get()`;
* `set()`;
* `has()`;
* `all()`;
* iteration;
* JSON serialization;
* binding a node to an array by reference.

Place this internal support under:

```text
ON\Data\Support
```

Suggested classes:

ON\Data\Support\Dot
ON\Data\Support\DefinitionNodeSupport

These may initially be based on Overnight’s current `Dot` and `Config`.

Do not expose them as the main data-definition API.

## 7.2 Definition nodes

Introduce an internal base class:

```php
namespace ON\Data\Definition;

abstract class DefinitionNode extends Config
{
    public function getRegistry(): Registry;

    public function getParent(): Registry|DefinitionInterface|FieldInterface|RelationInterface|null;
}
```

Its constructor must bind its `$items` to the corresponding portion of the Registry master array by reference.

Conceptual behavior:

```php
$collectionData = &$registryItems['collections']['users'];

$collection = new Collection(
    items: $collectionData,
    registry: $registry,
);
```

A mutation through the object must mutate the master array:

```php
$users = $registry->collection('users');

$users->description('Application users');

assert(
    $registry->all()['collections']['users']['description']
        === 'Application users'
);
```

A field mutation must do the same:

```php
$name = $users->field('name', 'string');

$name->required(true);

assert(
    $registry->all()['collections']['users']['fields']['name']['required']
        === true
);
```

## 7.3 Runtime wrapper cache

The Registry may cache typed wrapper instances for convenience and stable object identity:

```php
private array $instances = [];
```

The cache is runtime-only and is not part of `all()`.

Repeated lookups must return the same wrapper instance during one Registry lifetime:

```php
assert(
    $registry->getCollection('users')
        === $registry->getCollection('users')
);
```

The same applies to:

* fields;
* relations;
* views;
* nested display/interface nodes where practical.

The underlying master array remains authoritative. The wrapper cache is not a second metadata store.

---

# 8. Registry API

Preserve the current Registry methods where applicable and add view support.

Use these semantics:

```php
final class Registry extends Config
{
    public function collection(string $name): CollectionInterface;

    public function getCollection(
        string|CollectionInterface $collection
    ): CollectionInterface;

    public function hasCollection(string $name): bool;

    /**
     * @return array<string, CollectionInterface>
     */
    public function getCollections(): array;

    public function view(string $name): ViewDefinitionInterface;

    public function getView(
        string|ViewDefinitionInterface $view
    ): ViewDefinitionInterface;

    public function hasView(string $name): bool;

    /**
     * @return array<string, ViewDefinitionInterface>
     */
    public function getViews(): array;

    public function getDefinitionFiles(): array;

    public function all(): array;
}
```

## 8.1 `collection()`

```php
$registry->collection('users');
```

must:

1. Create the collection array when missing.
2. Apply collection defaults.
3. Default the table name to the collection name, preserving current behavior.
4. Record the source definition file, preserving current behavior.
5. Return the typed `CollectionInterface` wrapper.
6. Return the existing definition without resetting it when it already exists.

This last point follows the Config-style definition model and allows multiple extensions to enrich an existing collection.

## 8.2 `getCollection()`

`getCollection()` must return `CollectionInterface`, not `?CollectionInterface`.

Unknown names must throw a dedicated exception:

```php
CollectionNotFoundException
```

Optional access uses:

```php
$registry->hasCollection('users');
```

Passing an existing `CollectionInterface` returns it after verifying that it belongs to this Registry.

## 8.3 `view()` and `getView()`

Use the same create-versus-require semantics:

```php
$registry->view('user_summary');     // create or configure
$registry->getView('user_summary');  // require existing
```

Unknown views must throw:

```php
ViewNotFoundException
```

## 8.4 `register()`

Preserve `register()` only if it is currently used by Overnight or existing tests.

It must not store the passed object in the master array.

It must import or bind the definition data into:

```php
$items['collections'][$name]
```

and make the Registry-owned wrapper canonical.

If `register()` is unused, keep it temporarily for compatibility and mark its intended semantics with tests. Do not redesign unrelated registration APIs in this step.

---

# 9. Shared parent definition contract

Fields and relations currently assume their parent is always `CollectionInterface`.

Views must also own fields and relations.

Introduce only the minimum common contract needed for this.

Suggested name:

```php
ON\Data\Definition\DefinitionInterface
```

This is not another metadata representation. It is only a shared PHP interface.

```php
interface DefinitionInterface
{
    public function name(string $name): static;

    public function getName(): string;

    public function field(
        string $name,
        ?string $type = null,
    ): FieldInterface;

    public function getField(string $name): FieldInterface;

    public function hasField(string $name): bool;

    public function getFields(): FieldMap;

    /**
     * @param class-string<RelationInterface> $type
     */
    public function relation(
        string $name,
        string $type,
    ): RelationInterface;

    public function getRelation(string $name): RelationInterface;

    public function hasRelation(string $name): bool;

    public function getRelations(): RelationMap;

    public function getRegistry(): Registry;

    public function end(): Registry;

    public function metadata(
        string $key,
        mixed $value = null,
    ): mixed;
}
```

Then:

```php
CollectionInterface extends DefinitionInterface
```

and:

```php
ViewDefinitionInterface extends DefinitionInterface
```

Do not create `ModelConfig`, `CollectionConfig`, or `CompiledDefinition`.

---

# 10. Fields and FieldMap

## 10.1 Preserve the existing Field API

Migrate the existing `Field` and `FieldInterface` methods.

The only intentionally removed field configuration method is:

```php
$field->primaryKey(...)
```

`primaryKey()` now belongs to `Collection`.

All other methods must be preserved unless they directly depend on an unavailable external package.

## 10.2 Field parent

Change the field parent from:

```php
CollectionInterface
```

to:

```php
DefinitionInterface
```

The field must expose or retain access to its parent definition.

`end()` becomes:

```php
public function end(): DefinitionInterface;
```

At runtime, the actual return type is:

* `CollectionInterface` for collection fields;
* `ViewDefinitionInterface` for view fields.

PHPDoc generics may be used to preserve static-analysis precision, but do not introduce a large generic hierarchy in this step.

## 10.3 `isPrimaryKey()`

Keep:

```php
$field->isPrimaryKey();
```

but derive it from the parent collection:

```php
public function isPrimaryKey(): bool
{
    $parent = $this->getParent();

    return $parent instanceof CollectionInterface
        && in_array(
            $this->getName(),
            $parent->getPrimaryKey(),
            true,
        );
}
```

Do not store a second `pk` or `primaryKey` boolean on the field.

Remove the field-level primary-key value from the persisted definition array.

For fields belonging to views:

```php
$field->isPrimaryKey()
```

returns `false`.

View identity will be designed separately later.

## 10.4 FieldMap

Preserve the current public behavior of `FieldMap`.

Internally, it must become a typed facade over:

```php
$definitionData['fields']
```

It must not store `Field` objects as the source of truth.

Requirements:

* `has($name)` reads the master array.
* `get($name)` returns a cached or newly constructed field wrapper.
* `set()` updates the master array rather than storing the object directly.
* iteration yields `FieldInterface` objects.
* column lookups preserve current behavior.
* field order follows array insertion order.
* loading from a cached Registry reconstructs the correct wrappers.

Store the field class in the definition:

```php
[
    'class' => Field::class,
]
```

This allows future custom field subclasses and permits `ViewField` to have a distinct class.

## 10.5 Public `$fields`

If `Collection::$fields` is currently used publicly, preserve it:

```php
public FieldMap $fields;
```

It must be a facade bound to the collection’s master-array path.

Add or preserve:

```php
public function getFields(): FieldMap;
```

Do the same for views.

---

# 11. Relations and RelationMap

## 11.1 Preserve relation classes

Migrate the current relation hierarchy, including:

* `AbstractRelation`;
* `BelongsToRelation`;
* `HasOneRelation`;
* `HasManyRelation`;
* `FirstOfManyRelation`;
* `M2MRelation`;
* `M2MThrough`;
* `RelationInterface`;
* `RelationMap`.

Do not turn relation types into an enum.

Do not move relation-specific metadata into generic collection code.

## 11.2 Store relation class names

Every relation definition must contain its class:

```php
[
    'class' => HasManyRelation::class,
    'name' => 'posts',
    // Existing relation data.
]
```

After Registry cache restoration:

```php
$relation = $registry
    ->getCollection('users')
    ->getRelation('posts');

assert($relation instanceof HasManyRelation);
```

The same requirement applies to custom relation subclasses.

## 11.3 Relation parent

Change relation parent typing from:

```php
CollectionInterface
```

to:

```php
DefinitionInterface
```

Preserve:

```php
public function getParent(): DefinitionInterface;
```

and change:

```php
public function end(): DefinitionInterface;
```

Storage relation classes may continue requiring a collection target through:

```php
collection(string $collectionName)
getCollection(): CollectionInterface
```

A future view-specific relation class may resolve another view. Do not redesign target resolution in this step.

## 11.4 Composite relation keys

Preserve the composite relation-key changes already present in the `cycle-mutation` branch:

```php
innerKey(string|array $fieldName)
outerKey(string|array $fieldName)

innerKeys(): array
outerKeys(): array
```

Preserve the single-key convenience getters:

```php
getInnerKey()
getOuterKey()
getInnerField()
getOuterField()
```

They must continue throwing when called for a composite relation.

Update relation validation to use the collection-level primary-key array:

```php
count($target->getPrimaryKey())
```

Do not call a removed `PrimaryKeyDefinition`.

## 11.5 RelationMap

Like `FieldMap`, `RelationMap` becomes a facade over:

```php
$definitionData['relations']
```

It must lazily reconstruct the correct relation subclass using the stored `class` value.

It must not store relation objects in the master definition array.

Preserve its current public behavior and iteration semantics.

---

# 12. Collection-owned primary-key definition

## 12.1 Remove field-level declaration

Remove from `FieldInterface` and `SchemaTrait`:

```php
primaryKey(bool $primaryKey): self
```

Do not silently continue supporting both declaration styles.

There must be one authoritative source:

```php
$collection->primaryKey(...)
```

## 12.2 Collection API

Add or change these methods:

```php
interface CollectionInterface extends DefinitionInterface
{
    public function primaryKey(string ...$fieldNames): self;

    /**
     * Ordered canonical field names.
     *
     * @return non-empty-list<string>
     */
    public function getPrimaryKey(): array;

    /**
     * Always return a list, even for a simple key.
     *
     * @return non-empty-list<FieldInterface>
     */
    public function getPrimaryKeyFields(): array;

    /**
     * @return non-empty-list<string>
     */
    public function getPrimaryKeyColumns(): array;

    public function isCompositePrimaryKey(): bool;

    public function getKey(
        Key|array|string|int|float $value,
    ): Key;

    public function getKeyFromRecord(
        array $record,
        bool $allowColumnNames = true,
    ): Key;
}
```

Do not return different types for simple and composite primary keys.

This old behavior is forbidden:

```php
FieldInterface|array
```

`getPrimaryKeyFields()` always returns a list.

## 12.3 `primaryKey()` behavior

```php
$collection->primaryKey('id');
```

stores:

```php
['id']
```

Composite example:

```php
$collection->primaryKey('tenant_id', 'user_id');
```

stores:

```php
['tenant_id', 'user_id']
```

Requirements:

* At least one field name is required.
* Empty names are rejected.
* Duplicate names are rejected.
* Declared order is preserved.
* Referenced fields may be defined later in the fluent chain.
* Validation that fields exist may occur when primary-key fields are accessed or through an explicit Registry validation method added later.
* Recalling `primaryKey()` replaces the complete previous definition.
* `primaryKey()` does not mutate unrelated field metadata.
* It does not automatically make a field auto-increment.
* It does not infer a primary key named `id` unless explicitly configured.

The collection’s primary-key data must be stored directly at:

```php
$items['collections'][$name]['primaryKey']
```

---

# 13. Replace `PrimaryKeyDefinition` and `PrimaryKeyValue` with `Key`

## 13.1 Remove old classes

Delete:

```text
PrimaryKeyDefinition.php
PrimaryKeyValue.php
```

Do not retain them as wrappers around `Key`.

No new code in this package may depend on them.

## 13.2 Key class

Create:

```php
namespace ON\Data;

use JsonSerializable;
use ON\Data\Definition\Collection\CollectionInterface;
use Stringable;

final class Key implements Stringable, JsonSerializable
{
    /**
     * Values must already be ordered according to the
     * collection primary-key definition.
     *
     * @param non-empty-array<string, mixed> $values
     */
    public function __construct(
        private CollectionInterface $collection,
        private array $values,
    ) {
    }

    public function getCollection(): CollectionInterface
    {
        return $this->collection;
    }

    /**
     * Return the only key value.
     *
     * Throw when the key is composite.
     */
    public function getValue(): mixed;

    /**
     * Always return an associative array ordered according
     * to CollectionInterface::getPrimaryKey().
     *
     * @return non-empty-array<string, mixed>
     */
    public function getValues(): array;

    /**
     * Throw when the requested field is not part of the key.
     */
    public function getFieldValue(string $fieldName): mixed;

    public function isComposite(): bool;

    public function equals(self $other): bool;

    /**
     * Return a deterministic and unambiguous string.
     */
    public function getHash(): string;

    public function __toString(): string;

    public function jsonSerialize(): array;
}
```

Use the project-wide accessor convention:

```text
getCollection()
getValue()
getValues()
getFieldValue()
getHash()
```

Do not use:

```text
collection()
value()
values()
hash()
```

## 13.3 Key completeness

A `Key` always represents a complete identity.

It must never contain missing primary-key fields.

It must not support an incomplete state.

Therefore, do not add:

```php
isComplete()
```

Unresolved generated keys belong to the future mutation/Unit-of-Work layer, not to this class.

## 13.4 `getValue()`

For:

```php
$users->primaryKey('id');
$key = $users->getKey(10);
```

this returns:

```php
$key->getValue(); // 10
```

For a composite key:

```php
$key->getValue();
```

must throw:

```php
CompositeKeyException
```

Consumers use:

```php
$key->getValues();
```

instead.

## 13.5 `getValues()`

Simple key:

```php
[
    'id' => 10,
]
```

Composite key:

```php
[
    'post_id' => 10,
    'user_id' => 4,
]
```

The order must always match:

```php
$collection->getPrimaryKey();
```

## 13.6 Equality

Two keys are equal when:

1. Their collection names are equal.
2. Their ordered field names are equal.
3. Their values are strictly equal.

Initial implementation:

```php
public function equals(self $other): bool
{
    return $this->collection->getName()
        === $other->collection->getName()
        && $this->values === $other->values;
}
```

FieldType-aware normalization and comparison will be added only after the existing FieldType system is migrated.

Do not invent a temporary type-conversion system in this step.

## 13.7 Hash/string representation

`getHash()` must be:

* deterministic;
* unambiguous;
* safe as a PHP array key;
* safe for cache keys;
* independent of value separators;
* capable of distinguishing integer `1` from string `"1"` where JSON preserves that distinction;
* based on the collection name and ordered named values.

Use a versioned canonical payload.

Example conceptual payload:

```php
[
    'collection' => 'post_user',
    'values' => [
        'post_id' => 10,
        'user_id' => 4,
    ],
]
```

Recommended representation:

```text
k1:<base64url-json>
```

Use:

```php
JSON_THROW_ON_ERROR
| JSON_UNESCAPED_SLASHES
| JSON_UNESCAPED_UNICODE
| JSON_PRESERVE_ZERO_FRACTION
```

`__toString()` returns `getHash()`.

Do not implement URL parsing or a separate codec in this step.

The future REST integration may decide whether the same representation is suitable for route identifiers.

## 13.8 Key creation through Collection

Application code should normally not instantiate `Key` directly.

Simple key:

```php
$key = $users->getKey(10);
```

Associative simple key:

```php
$key = $users->getKey([
    'id' => 10,
]);
```

Composite associative key:

```php
$key = $postUsers->getKey([
    'post_id' => 10,
    'user_id' => 4,
]);
```

Composite positional key:

```php
$key = $postUsers->getKey([10, 4]);
```

Existing key:

```php
$key = $users->getKey($existingKey);
```

When an existing `Key` belongs to another collection, throw:

```php
InvalidPrimaryKeyException
```

## 13.9 Field and column names

`getKey()` and `getKeyFromRecord()` must support field names.

For compatibility with the current primary-key extraction behavior, they may also support storage column names.

Example:

```php
$users
    ->field('id', 'integer')
    ->column('user_id')
    ->end();

$key = $users->getKey([
    'user_id' => 10,
]);
```

The resulting values are canonicalized to field names:

```php
[
    'id' => 10,
]
```

## 13.10 Positional values

A positional array is valid only when its size exactly matches the primary-key size.

```php
$postUsers->getKey([10, 4]);
```

maps according to:

```php
$postUsers->getPrimaryKey();
```

Invalid sizes throw `InvalidPrimaryKeyException`.

## 13.11 Scalar values

A scalar value is accepted only for a single-field primary key.

This must throw:

```php
$postUsers->getKey('10-4');
```

Do not retain implicit composite URL decoding from `PrimaryKeyDefinition`.

External encoding/decoding is a later boundary concern.

---

# 14. ViewDefinition foundation

## 14.1 Purpose in this step

Introduce the structural foundation for business/application models.

Do not design the complete semantic-view query language yet.

This step establishes that a view:

* is registered in the same Registry;
* is stored in the same master array;
* has a name;
* has a source definition;
* can own fields;
* can own relations;
* can own metadata;
* supports `->end()`;
* survives Registry array caching and restoration.

Expressions, aggregates, relation-derived fields, nested output cardinality, and write mappings belong to later steps.

## 14.2 Classes

Create:

```text
ON\Data\Definition\View\ViewDefinition
ON\Data\Definition\View\ViewDefinitionInterface
ON\Data\Definition\View\ViewField
```

`ViewField` may initially inherit from the existing `Field` implementation so that it preserves:

* type metadata;
* display metadata;
* interface metadata;
* validation metadata;
* mapping-related metadata;
* `end()`.

It must have its own stored class discriminator:

```php
'class' => ViewField::class
```

Do not add speculative methods such as:

```text
aggregate()
count()
one()
many()
explode()
expression()
resolver()
```

in this step.

Those methods depend on decisions about the future query-reference and expression systems.

## 14.3 ViewDefinition interface

```php
interface ViewDefinitionInterface extends DefinitionInterface
{
    public function source(
        string|DefinitionInterface $source
    ): self;

    public function getSourceName(): ?string;

    public function getSource(): DefinitionInterface;
}
```

`source()` stores only the definition name in the master array.

Example:

```php
$registry
    ->view('user_summary')
        ->source('users')

        ->field('id', 'integer')
            ->end()

        ->field('name', 'string')
            ->end()
        ->end();
```

Master-array fragment:

```php
[
    'views' => [
        'user_summary' => [
            'name' => 'user_summary',
            'source' => 'users',
            'fields' => [
                'id' => [
                    'class' => ViewField::class,
                    'name' => 'id',
                    'type' => 'integer',
                ],
                'name' => [
                    'class' => ViewField::class,
                    'name' => 'name',
                    'type' => 'string',
                ],
            ],
            'relations' => [],
            'metadata' => [],
        ],
    ],
]
```

## 14.4 Source resolution

`getSourceName()` returns the stored name or `null`.

`getSource()` resolves the name through the Registry.

It may resolve either:

* a collection;
* another view.

Unknown sources throw:

```php
DefinitionNotFoundException
```

Circular view-source validation is deferred until views can execute queries.

## 14.5 View relations

`ViewDefinition` must own a `RelationMap` and support the generic:

```php
relation(string $name, string $class)
```

API.

It may expose the existing convenience methods where they remain semantically valid.

Do not create generic relation execution behavior.

The relation class remains responsible for defining what its metadata means.

---

# 15. Adapter-specific defaults to remove

The standalone definition package must not import:

```php
Cycle\ORM\Mapper\StdMapper
ON\ORM\Select\Source
```

Preserve the existing collection methods:

```php
mapper(...)
getMapper()

source(...)
getSource()
```

but change their defaults to:

```php
null
```

Their types become nullable where required:

```php
public function mapper(?string $mapper): self;

public function getMapper(): ?string;

public function source(?string $source): self;

public function getSource(): ?string;
```

These values remain opaque class-string metadata in this step.

Later ORM and database-adapter specifications will decide how they are interpreted.

Keep:

```php
entity()
getEntity()
```

and the `stdClass` default unless a concrete incompatibility is found.

No Cycle or Doctrine class may be required by the definition package.

---

# 16. Metadata traits and nested configuration objects

All migrated traits must write into the bound node array.

For example, `MetadataTrait` must not have:

```php
protected array $metadata = [];
```

as an independent source of truth.

Instead:

```php
$this->items['metadata']
```

is authoritative.

The same rule applies to:

* display configuration;
* interface configuration;
* schema configuration;
* validation messages;
* generated relation metadata;
* relation ordering;
* relation conditions;
* relation load configuration.

Any nested object created by methods such as:

```php
$field->display(...)
$field->interface(...)
$relation->display(...)
$relation->interface(...)
```

must bind to a nested array inside the Registry definition.

The nested object class must be stored when required for reconstruction.

---

# 17. Exceptions

Preserve existing exceptions and add only the missing focused exceptions:

```text
CollectionNotFoundException
ViewNotFoundException
DefinitionNotFoundException
InvalidPrimaryKeyException
CompositeKeyException
PrimaryKeyNotDefinedException
FieldNotFoundException
RelationNotFoundException
```

Reuse current exception classes where their semantics already match.

Do not throw generic `LogicException` or `InvalidArgumentException` for all public definition errors when a focused exception exists.

Internal invariant failures may still use `LogicException`.

---

# 18. Compatibility requirements

## 18.1 Preserve methods

Before changing implementation, inventory all public methods under the current definition tree.

Except for the explicit changes below, preserve method names, arguments, return behavior, and defaults.

Intentional API changes:

```text
ON\ORM\Definition → ON\Data\Definition

Field::primaryKey() removed

Collection::primaryKey() added

Collection::getPrimaryKey()
    changes from PrimaryKeyDefinition
    to ordered list<string>

Collection::getPrimaryKeyFields()
    changes from FieldInterface|array
    to list<FieldInterface>

PrimaryKeyDefinition removed

PrimaryKeyValue removed

Key added

Field and Relation parent generalized
    from CollectionInterface
    to DefinitionInterface

Registry::getCollection()
    returns CollectionInterface
    and throws when missing

Cycle-specific source and mapper defaults removed

ViewDefinition added
```

## 18.2 Preserve all current metadata

A configuration call present in the existing definition API must still be represented in `Registry::all()`.

Examples include:

* aliases;
* columns;
* required;
* searchable;
* sensible;
* defaults;
* cast-default behavior;
* typecasts;
* validation rules;
* validation messages;
* descriptions;
* schema data;
* auto-increment;
* nullable;
* hidden;
* unique;
* indexed;
* comments;
* display data;
* interface data;
* relation loading;
* relation conditions;
* relation ordering;
* cascade;
* relation keys;
* loaders;
* collection scope;
* repository;
* entity;
* parent collection;
* notes;
* metadata.

Do not silently drop metadata because it is not yet used by the new repository.

## 18.3 Preserve relation-generated fields

Some relation definitions may create or modify fields on their parent collection.

That behavior must continue working, but those generated fields must now be written into the Registry master array.

---

# 19. Tests

## 19.1 Characterization tests

Before refactoring behavior, port or recreate tests covering the existing definition DSL.

At minimum, test every public configuration/getter pair.

Examples:

```php
$field->required(true);
assert($field->isRequired());

$field->column('user_name');
assert($field->getColumn() === 'user_name');

$relation->nullable(true);
assert($relation->isNullable());
```

Do this for:

* Collection;
* Field;
* SchemaTrait;
* Display;
* Interface;
* Metadata;
* every existing relation class;
* FieldMap;
* RelationMap.

## 19.2 Master-array mutation tests

Test that changing any node updates `Registry::all()` immediately.

Include:

* collection property;
* field property;
* relation property;
* display property;
* interface property;
* metadata;
* schema property.

## 19.3 No-object test

Recursively inspect:

```php
$registry->all();
```

and fail if any object appears.

## 19.4 Cache round-trip test

Build a definition containing:

* multiple collections;
* fields;
* display/interface metadata;
* simple relation;
* composite relation;
* M2M relation;
* custom relation subclass;
* simple primary key;
* composite primary key;
* view;
* view fields;
* view relation.

Then:

```php
$array = $registry->all();

$restored = new Registry($array);

assert($restored->all() === $array);
```

Also verify the restored wrapper classes and getters.

## 19.5 Fluent chaining test

Verify a complete multi-collection definition works as one chain:

```php
$registry
    ->collection('users')
        ->primaryKey('id')
        ->field('id', 'integer')
            ->end()
        ->end()

    ->collection('posts')
        ->primaryKey('id')
        ->field('id', 'integer')
            ->end()
        ->belongsTo('author', 'users')
            ->innerKey('user_id')
            ->outerKey('id')
            ->end()
        ->end()

    ->view('user_summary')
        ->source('users')
        ->field('id', 'integer')
            ->end()
        ->end();
```

## 19.6 Wrapper identity test

```php
assert(
    $registry->getCollection('users')
        === $registry->getCollection('users')
);

assert(
    $registry->getCollection('users')->getField('id')
        === $registry->getCollection('users')->getField('id')
);
```

## 19.7 Primary-key tests

Cover:

* simple field-name key;
* simple column-name key;
* composite associative key;
* composite positional key;
* extraction from a full record;
* canonical field ordering;
* missing component;
* duplicate PK field declaration;
* empty PK declaration;
* scalar passed to composite collection;
* Key passed to a different collection;
* `getValue()` on simple key;
* `getValue()` on composite key;
* `getValues()`;
* `getFieldValue()`;
* strict equality;
* inequality by collection;
* deterministic hash;
* JSON serialization;
* `Field::isPrimaryKey()` derived from collection;
* restored Registry producing equivalent keys.

## 19.8 Relation composite-key tests

Cover:

* one inner and one outer key;
* multiple inner and outer keys;
* mismatched arity;
* duplicate keys;
* singular getters throwing for composite keys;
* target primary-key arity validation;
* relation restoration from cached arrays.

## 19.9 View tests

Cover:

* create view;
* retrieve view;
* missing view exception;
* set source using string;
* set source using CollectionInterface;
* resolve source;
* view field creation;
* view relation creation;
* `field->end()` returning view;
* `relation->end()` returning view;
* `view->end()` returning Registry;
* view cache round trip.

## 19.10 Dependency test

Add a CI check or test ensuring the package source does not reference:

```text
Cycle\
Doctrine\
ON\ORM\
ON\RestApi\
```

References in migration documentation are acceptable; production classes are not.

---

# 20. Implementation order

Implement in this sequence.

## Phase 1 — Package bootstrap

1. Create the new repository.
2. Add Composer configuration.
3. Configure `ON\Data\`.
4. Add PHPUnit.
5. Add coding-style and static-analysis configuration consistent with Overnight.
6. Add the minimal internal Config/Dot support.

## Phase 2 — Mechanical extraction

1. Copy the entire current definition tree.
2. Change namespaces mechanically.
3. Make the code compile before redesigning storage.
4. Port existing tests.
5. Remove imports that belong only to unrelated Overnight components where possible.

## Phase 3 — Master-array Registry

1. Make Registry own `collections` and `views` arrays.
2. Introduce array-bound `DefinitionNode`.
3. Convert Collection properties to array-backed accessors.
4. Convert Field properties to array-backed accessors.
5. Convert Relation properties to array-backed accessors.
6. Convert metadata/display/interface/schema objects.
7. Convert FieldMap.
8. Convert RelationMap.
9. Add wrapper caching.
10. Add `all()` and round-trip tests.

## Phase 4 — Primary-key refactor

1. Add collection-level `primaryKey()`.
2. Remove field-level `primaryKey()`.
3. Derive `Field::isPrimaryKey()`.
4. Remove `PrimaryKeyDefinition`.
5. Remove `PrimaryKeyValue`.
6. Add `Key`.
7. Add `Collection::getKey()`.
8. Add `Collection::getKeyFromRecord()`.
9. Update relation primary-key validation.
10. Add all simple/composite tests.

## Phase 5 — ViewDefinition foundation

1. Add the `views` Registry node.
2. Add `DefinitionInterface`.
3. Generalize Field parent.
4. Generalize Relation parent.
5. Add ViewDefinition.
6. Add ViewField.
7. Add Registry view methods.
8. Add source resolution.
9. Add view round-trip tests.

## Phase 6 — Cleanup

1. Remove Cycle ORM dependency.
2. Remove old Overnight namespace references.
3. Confirm all migrated metadata remains available.
4. Run static analysis.
5. Run coding style.
6. Run complete tests.
7. Document intentional compatibility changes.

---

# 21. Definition of done

This step is complete when all of the following are true:

1. The new repository installs independently through Composer.
2. Its root namespace is `ON\Data`.
3. It does not depend on Cycle ORM, Doctrine ORM, or Overnight.
4. The existing Overnight definition DSL is available under the new namespace.
5. A complete collection can still be defined in one fluent chain using `end()`.
6. All collection, field, relation, display, interface, schema, and metadata values are held in one Registry-owned array.
7. `Registry::all()` recursively contains no definition objects.
8. A Registry can be reconstructed from `Registry::all()`.
9. Relation subclasses survive that reconstruction.
10. Custom relation subclasses survive that reconstruction.
11. Primary keys are declared only on collections.
12. Simple and composite primary keys use the same `Key` class.
13. Primary-key APIs never change return type based on key cardinality.
14. Composite relation keys continue working.
15. `getCollection()` returns `CollectionInterface` or throws.
16. `ViewDefinition` can be created, cached, restored, and can own fields and relations.
17. No query, mapping-execution, persistence, REST, or Unit-of-Work implementation has leaked into this step.
18. Tests demonstrate all required behavior.

---

# 22. Do not make these changes during implementation

Do not:

* redesign all method names;
* remove `end()`;
* replace fluent definitions with arrays as the user-facing API;
* add a compiled Registry;
* create `CollectionConfig`;
* create `ModelConfig`;
* create a separate DataType system;
* implement queries;
* implement aggregates;
* implement relation handlers;
* implement database adapters;
* implement the ORM;
* introduce generic relation connect/disconnect behavior;
* store objects in Registry data;
* retain Cycle-specific defaults;
* infer primary keys automatically;
* retain both field-level and collection-level primary-key declarations;
* support incomplete `Key` instances;
* add encoded composite-string parsing to `Collection::getKey()`;
* invent final ViewField expression APIs before the query-reference system is specified.

When an existing method or metadata value appears questionable but is not explicitly changed by this specification, preserve it and add a test. Record the concern for a later design step instead of redesigning it now.
