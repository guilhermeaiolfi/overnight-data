# Standalone Data Layer and ORM Architecture

## First Specification Draft — Version 0.1

## 1. Purpose

This project will be a standalone, metadata-driven data layer for PHP.

It will provide:

* persistent collection definitions;
* application-facing business views;
* fluent queries;
* expressions and aggregates;
* relation loading through pluggable relation classes and handlers;
* mapping through the existing Overnight Mapper, `FieldType`, and Representation systems;
* direct insert, update, and delete operations;
* structured support for simple and composite primary keys;
* transaction and mutation dependency handling;
* an optional ORM layer containing identity mapping, entity mapping, and Unit of Work.

The project must not be designed around the Overnight REST API extension.

The REST API extension will eventually become one consumer of this library, alongside:

* application repositories;
* services;
* CLI jobs;
* reports;
* admin interfaces;
* GraphQL;
* data importers;
* background jobs;
* normal ORM-style application code.

The data layer must be useful without the ORM module, and the ORM module must be built from the same query, mapping, identity, and mutation foundations.

---

# 2. Core architectural principles

## 2.1 The Registry definition is the source of truth

There will be no compiled Registry representation.

There will be no parallel `CollectionConfig`, `ModelConfig`, or other runtime metadata hierarchy.

The Registry itself will:

* own one master array;
* expose typed collection, field, relation, and view definition objects;
* be directly cacheable;
* be directly restorable from its cached array;
* be consumed directly by queries, mappers, relation handlers, mutations, and the ORM.

The typed definition objects are views over parts of the same master array. They do not maintain independent copies of metadata.

## 2.2 Preserve the existing Overnight definition API

The current Overnight ORM definition system is the starting point.

Unless a current method is incompatible with this architecture, the standalone project must preserve:

* current method names;
* current fluent structure;
* current metadata;
* `->end()` navigation;
* current field classes and interfaces;
* current relation classes and interfaces;
* current field maps and relation maps;
* current display and UI metadata;
* current validation metadata;
* current relation registration mechanism.

The initial definition refactor is limited to four architectural changes:

1. Store all definitions in one Registry-owned master array.
2. Introduce the new `Key` representation and collection-level composite-key support.
3. Move `primaryKey()` from individual fields to the collection.
4. Introduce `ViewDefinition` for application/business models.

Additional changes are allowed only when required to make those four changes coherent with the standalone data layer.

## 2.3 Collections represent persisted data

A collection describes data as it is persisted.

It owns:

* its database or connection name;
* its table or physical source;
* its fields;
* its storage columns;
* its ordered primary-key definition;
* its relations;
* persistence metadata;
* validation and interface metadata already present in Overnight.

Collections are inherently writable unless explicitly configured otherwise.

## 2.4 Views represent application data

A view describes data as the application wants to consume it.

A view may contain:

* fields copied from its source;
* renamed fields;
* fields reached through relations;
* SQL-computed fields;
* aggregate fields;
* nested relations;
* filtered relations;
* values loaded by custom relation handlers;
* application-computed fields;
* default filters and ordering.

A view is not necessarily a database view.

It is an application or business model.

Views are read-only by default.

Writable views may be introduced later, but writes must use explicit field-to-collection mappings. Write behavior must never be inferred from arbitrary joined or computed fields.

## 2.5 Relations remain pluggable classes

Relations are not a closed enum understood by generic ORM code.

Each relation type is represented by a relation definition class.

A new relation type is introduced by registering:

* its relation definition class;
* its query/load handler;
* optionally, its mutation handler;
* optionally, relation-specific runtime conveniences.

Generic query and mutation code must not contain conditionals for every known relation class.

## 2.6 `FieldType` remains the type system

The project will use the existing:

* `FieldTypeInterface`;
* `FieldTypeRegistry`;
* `FieldContext`;
* `RepresentationInterface`;
* conversion gateway;
* structural mappers;
* field resolvers;
* `map()` entry point.

There will be no second data-type abstraction.

Database adapters may have their own low-level DBAL types, but those are adapter details. The application-facing conversion authority remains `FieldType`.

## 2.7 Composite primary keys are first-class

No internal API may assume that a primary key contains one field.

All identity-related code must work with:

* one-field primary keys;
* multi-field primary keys;
* foreign keys referencing composite keys;
* composite pivot identities;
* partially generated keys;
* simple and composite relation mappings.

Scalar key input is a convenience accepted only at public boundaries for collections with a one-field primary key.

## 2.8 Getter naming convention

Read-only accessors use:

```php
getSomething()
```

Boolean questions use:

```php
isSomething()
hasSomething()
supportsSomething()
```

Configuration and command methods remain verbs without `get`:

```php
field()
relation()
primaryKey()
select()
where()
execute()
persist()
```

---

# 3. High-level component structure

```text
Registry and Definitions
    ├── Collection
    ├── Field
    ├── Relation
    └── ViewDefinition

FieldType, Representations, and Mapper

DataManager
    ├── QueryFactory
    ├── QueryExecutor
    ├── MutationFactory
    ├── TransactionManager
    └── DatabaseAdapterRegistry

Query
    ├── QuerySpec
    ├── FieldRef / RelationRef
    ├── Expressions
    ├── Aggregates
    ├── RelationLoadTree
    └── RelationHandlers

Mutation
    ├── Insert / Update / Delete
    ├── MutationPlan
    ├── Operations
    ├── ValueRef
    └── OperationExecutor

Optional ORM
    ├── Session
    ├── EntityMapping
    ├── IdentityMap
    ├── EntityState
    ├── ChangeSet
    └── UnitOfWork

Adapters
    ├── Cycle Database adapter
    └── Doctrine DBAL adapter
```

Dependency direction:

```text
Definitions
    ↓
FieldType / Mapper
    ↓
Query and Mutation Core
    ↓
Database Adapter
    ↓
Cycle Database or Doctrine DBAL
```

The ORM depends on the data core:

```text
ORM
 ├── Definitions
 ├── Query
 ├── Mapping
 ├── Key
 └── Mutation
```

The data core must not depend on the ORM.

The data core must not depend on the REST API extension.

---

# 4. Registry and definition storage

## 4.1 Registry master array

The Registry owns one array similar to other Overnight Config objects.

Conceptual structure:

```php
[
    'collections' => [
        'users' => [
            'table' => 'users',
            'database' => 'default',
            'primaryKey' => ['id'],
            'fields' => [
                // Existing Overnight field structure.
            ],
            'relations' => [
                // Existing Overnight relation structure.
            ],
            'metadata' => [
                // Existing metadata.
            ],
        ],
    ],

    'views' => [
        'user_summary' => [
            'source' => 'users',
            'identity' => ['id'],
            'fields' => [
                // View fields.
            ],
            'relations' => [
                // View relations.
            ],
            'metadata' => [],
        ],
    ],
]
```

This array is not a compiled output. It is the definition itself.

## 4.2 Registry API

The existing Registry API should be preserved and expanded minimally:

```php
interface RegistryInterface
{
    public function collection(string $name): CollectionInterface;

    public function getCollection(string $name): CollectionInterface;

    public function hasCollection(string $name): bool;

    public function view(string $name): ViewDefinitionInterface;

    public function getView(string $name): ViewDefinitionInterface;

    public function hasView(string $name): bool;

    public function getDefinition(string $name): QueryableDefinitionInterface;

    /**
     * Return the complete master definition array.
     */
    public function all(): array;
}
```

Semantics:

* `collection('users')` creates the definition when missing or returns the existing definition for fluent configuration.
* `getCollection('users')` requires the collection to exist.
* `view('user_summary')` creates or returns a view for configuration.
* `getView('user_summary')` requires the view to exist.
* `getDefinition()` resolves either a collection or a view.
* `all()` returns the cacheable master array.

The constructor must be able to receive a previously cached master array:

```php
$registry = new Registry($cachedDefinition);
```

## 4.3 Definition objects

`Collection`, `Field`, `Relation`, and `ViewDefinition` objects must not copy their metadata.

Each object is connected to:

* the root Registry;
* its path in the Registry array;
* its parent definition.

The Registry should cache typed wrapper instances by definition path, so repeated calls return stable objects:

```php
$registry->getCollection('users')
    === $registry->getCollection('users');
```

This is useful because `Key` contains a `CollectionInterface`.

## 4.4 Definition cacheability

The Registry array must remain cacheable.

Definitions should store:

* scalar values;
* arrays;
* class strings;
* method names;
* exportable enum values;
* normalized expression definition arrays;
* cache-safe callable arrays when already supported.

Definitions should not require runtime service objects.

Closures should not be accepted as permanent Registry metadata unless the Registry is explicitly configured as non-cacheable.

View expressions and resolvers should therefore be stored as:

* normalized expression arrays;
* resolver class strings plus arguments;
* relation class strings plus relation metadata.

## 4.5 Registry validation

There is no compilation step, but there must be validation.

```php
interface RegistryValidatorInterface
{
    public function validate(RegistryInterface $registry): void;
}
```

Validation includes:

* referenced primary-key fields exist;
* primary-key fields are not repeated;
* relation targets exist;
* relation key mappings have compatible arity;
* relation handler registrations exist;
* view sources exist;
* view field paths are valid;
* view identities refer to view fields;
* field types exist in the FieldType Registry;
* cached definition values are exportable;
* writable definitions do not write through ambiguous expressions.

Validation may run:

* explicitly during development;
* when the DataManager is constructed;
* before the definition is cached;
* in tests.

Validation must not produce another metadata representation.

---

# 5. Collection definitions and primary keys

## 5.1 Existing collection DSL

The existing fluent style remains:

```php
$registry
    ->collection('users')
        ->table('users')
        ->primaryKey('id')

        ->field('id', 'integer')
            ->column('id')
            ->required(true)
            ->end()

        ->field('name', 'string')
            ->required(true)
            ->filterable()
            ->searchable()
            ->end()

        ->field('email', 'string')
            ->filterable()
            ->end()

        ->hasMany('posts')
            // Existing relation configuration methods.
            ->end()
        ->end();
```

Composite key:

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
        ->end();
```

## 5.2 Collection-level primary-key API

```php
interface CollectionInterface extends QueryableDefinitionInterface
{
    public function primaryKey(string ...$fields): self;

    /**
     * Canonical ordered field names.
     *
     * @return non-empty-list<string>
     */
    public function getPrimaryKey(): array;

    /**
     * Existing field objects in canonical key order.
     */
    public function getPrimaryKeyFields(): FieldMap;

    public function hasCompositePrimaryKey(): bool;

    public function createKey(
        mixed $value,
        string $representation = PhpRepresentation::class,
    ): Key;

    public function extractKey(
        array|object $record,
        string $representation = PhpRepresentation::class,
    ): Key;
}
```

`primaryKey()` stores field names directly on the collection:

```php
[
    'primaryKey' => ['post_id', 'user_id'],
]
```

The declared order is canonical and must be preserved.

## 5.3 Field primary-key behavior

The field-level configuration method is removed:

```php
$field->primaryKey(true);
```

`FieldInterface::isPrimaryKey()` may remain as a derived query:

```php
public function isPrimaryKey(): bool
{
    return in_array(
        $this->getName(),
        $this->getCollection()->getPrimaryKey(),
        true,
    );
}
```

Primary-key status is never stored independently in both the collection and field.

---

# 6. Key

## 6.1 Purpose

`Key` is the one public representation of a resolved collection identity.

It replaces the coordination currently required between a primary-key definition and a primary-key value.

```php
final readonly class Key implements
    Stringable,
    JsonSerializable
{
    /**
     * @param non-empty-array<string, mixed> $values
     */
    public function __construct(
        private CollectionInterface $collection,
        private array $values,
    ) {
    }

    public function getCollection(): CollectionInterface;

    /**
     * Return the single value.
     *
     * Throws when the key is composite.
     */
    public function getValue(): mixed;

    /**
     * Always return an associative array in canonical PK order.
     *
     * @return non-empty-array<string, mixed>
     */
    public function getValues(): array;

    public function getFieldValue(string $field): mixed;

    public function isComposite(): bool;

    public function equals(self $other): bool;

    /**
     * Stable machine-safe identity-map key.
     */
    public function getHash(): string;

    /**
     * Human-readable diagnostic representation.
     */
    public function getDebugString(): string;

    public function jsonSerialize(): array;

    public function __toString(): string;
}
```

## 6.2 Key creation

Simple key:

```php
$key = $users->createKey(10);
```

Associative simple key:

```php
$key = $users->createKey([
    'id' => 10,
]);
```

Composite key:

```php
$key = $postUsers->createKey([
    'post_id' => 10,
    'user_id' => 4,
]);
```

Positional composite key may be accepted:

```php
$key = $postUsers->createKey([10, 4]);
```

The values are assigned according to the canonical primary-key order.

Passing an existing `Key` is allowed only when it belongs to the same collection:

```php
$key = $users->createKey($existingKey);
```

## 6.3 Normalization

Each key component is normalized through its existing field and `FieldType`.

Examples:

* integer field: `'10'` becomes `10`;
* UUID field: normalized using its FieldType;
* datetime key: normalized to the canonical PHP representation;
* decimal or large integer: normalized according to its FieldType.

The `Key` stores canonical PHP values.

It does not store raw HTTP, JSON, or database representations.

## 6.4 Equality

Two keys are equal when:

* they refer to the same collection definition;
* they contain the same ordered primary-key fields;
* their normalized PHP values are equal according to the key field types.

The first implementation may use strict comparison after FieldType normalization.

A future field-type comparison extension may be introduced if strict normalized comparison proves insufficient.

## 6.5 String form

`getHash()` and `__toString()` return an unambiguous, versioned representation.

Conceptual payload:

```php
[
    'collection' => 'post_user',
    'values' => [
        ['post_id', 10],
        ['user_id', 4],
    ],
]
```

The complete payload may be encoded as canonical JSON and base64url:

```text
k1.<base64url-payload>
```

It must not depend on separators between raw values.

A separate `PrimaryKeyDefinition` class is not required.

## 6.6 Unresolved identities

A `Key` is always resolved.

A new record waiting for a generated primary-key component has:

```php
null
```

as its current key.

Generated or deferred field components are represented by `ValueRef` inside mutation operations, not inside `Key`.

Once all primary-key components are available, the record state creates its `Key`.

---

# 7. Queryable definitions

Collections and views share a small common contract.

This is not a Config layer. It is only a shared definition interface.

```php
interface QueryableDefinitionInterface
{
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

    public function end(): RegistryInterface;
}
```

The existing collection interface remains richer than this common contract.

The shared interface exists so that:

```php
$data->query($collection);
$data->query($view);
```

can use the same query object.

---

# 8. View definitions

## 8.1 Purpose

A `ViewDefinition` represents a reusable application-facing data model.

Example conceptual output:

```php
[
    'id' => 10,
    'name' => 'Guilherme',
    'post_count' => 24,
    'latest_post_title' => 'Architecture Draft',
    'posts' => [
        // Nested view records.
    ],
]
```

The view itself determines what each field means.

## 8.2 ViewDefinition API

```php
interface ViewDefinitionInterface extends QueryableDefinitionInterface
{
    public function source(
        string|QueryableDefinitionInterface $source,
    ): self;

    public function getSource(): QueryableDefinitionInterface;

    /**
     * Application identity, not necessarily a database primary key.
     */
    public function identity(string ...$fields): self;

    /**
     * @return list<string>
     */
    public function getIdentity(): array;

    public function hasIdentity(): bool;

    public function readOnly(bool $readOnly = true): self;

    public function isReadOnly(): bool;

    public function field(
        string $name,
        ?string $type = null,
    ): ViewFieldInterface;
}
```

Example:

```php
$registry
    ->view('user_summary')
        ->source('users')
        ->identity('id')

        ->field('id', 'integer')
            ->from('id')
            ->end()

        ->field('name', 'string')
            ->from('name')
            ->end()

        ->field('post_count', 'integer')
            ->expression(
                x()->count('posts.id')
            )
            ->end()

        ->field('latest_post_title', 'string')
            ->from('posts.title')
            ->one()
            ->orderBy('posts.created_at', 'DESC')
            ->end()

        ->relation('posts', ViewRelation::class)
            ->from('posts')
            ->many()
            ->end()
        ->end();
```

The exact names of the new view-specific methods are subject to confirmation, but the represented concepts are required.

## 8.3 View field sources

A view field may have one of these source kinds.

### Direct field or path

```php
->field('customer_name', 'string')
    ->from('customer.name')
    ->end()
```

### Database expression

```php
->field('full_name', 'string')
    ->expression(
        x()->concat('first_name', x()->value(' '), 'last_name')
    )
    ->end()
```

### Aggregate expression

```php
->field('post_count', 'integer')
    ->expression(
        x()->count('posts.id')
    )
    ->end()
```

### To-one relation-derived field

```php
->field('latest_post_title', 'string')
    ->from('posts.title')
    ->one()
    ->orderBy('posts.created_at', 'DESC')
    ->end()
```

### Application resolver

```php
->field('display_label', 'string')
    ->resolver(UserDisplayLabelResolver::class)
    ->end()
```

An application resolver receives already loaded PHP values and runs after database hydration.

Application-resolved fields are not automatically:

* filterable;
* sortable;
* groupable;
* usable in database predicates.

Those abilities must be explicitly provided by a custom query expression or handler.

## 8.4 View cardinality

When a field crosses a to-many relation, the view must specify its semantic cardinality.

Supported concepts:

```text
one
many
aggregate
exists
explode
```

Examples:

```php
->field('latest_title')
    ->from('posts.title')
    ->one()
    ->orderBy('posts.created_at', 'DESC')
    ->end()
```

```php
->field('post_titles')
    ->from('posts.title')
    ->many()
    ->end()
```

```php
->field('post_count')
    ->expression(x()->count('posts.id'))
    ->end()
```

```php
->field('has_posts')
    ->expression(x()->exists('posts'))
    ->end()
```

`explode` means that the relation deliberately changes root-row cardinality.

Without an explicit cardinality instruction, traversing a to-many relation as a scalar field is invalid.

## 8.5 View identity

A view can be:

* identified;
* unidentified.

An identified view may participate in:

* deduplication;
* result assembly;
* ORM identity mapping;
* mapping to tracked entities.

An unidentified view represents an arbitrary result shape and cannot be tracked as an entity.

By default, a view may inherit the source collection identity only when every identity field is present as an unchanged direct field.

Otherwise, `identity()` is required.

## 8.6 View writes

Version 1 views are read-only.

Future writable views must explicitly define:

* the destination collection;
* destination field for each writable field;
* behavior for missing destination fields;
* relation write rules;
* optimistic lock field;
* identity-to-primary-key mapping.

Computed and aggregate fields remain read-only unless a custom mutation handler is supplied.

---

# 9. Fields, FieldType, and Representations

## 9.1 Existing FieldInterface

The existing field definition methods remain unless directly contradicted by collection-level primary keys.

This includes current concepts such as:

* name;
* alias;
* type;
* column;
* display;
* interface;
* required;
* filterable;
* searchable;
* sensible;
* validation;
* typecast;
* description;
* generated relation metadata;
* `end()`.

`primaryKey()` is removed from field configuration.

`isPrimaryKey()` remains derived for collection fields.

## 9.2 Parent return type

Collection fields return their collection from `end()`.

View fields return their view from `end()`.

This may be represented with PHPDoc generics:

```php
/**
 * @template TParent of QueryableDefinitionInterface
 */
interface FieldInterface
{
    /**
     * @return TParent
     */
    public function end(): QueryableDefinitionInterface;
}
```

Alternatively, specialized interfaces may provide stronger native return types:

```php
interface CollectionFieldInterface extends FieldInterface
{
    public function end(): CollectionInterface;
}

interface ViewFieldInterface extends FieldInterface
{
    public function end(): ViewDefinitionInterface;
}
```

No field method forwards calls to its parent.

## 9.3 Conversion pipeline

Database result:

```text
database value
    ↓
FieldType::toPhp(DatabaseRepresentation, ...)
    ↓
canonical PHP value
    ↓
relation assembly
    ↓
structural mapper
    ↓
array, DTO, object, or entity
```

Database write:

```text
PHP value
    ↓
FieldType::fromPhp(DatabaseRepresentation, ...)
    ↓
database adapter parameter
```

Wire input:

```text
wire value
    ↓
FieldType::toPhp(WireRepresentation, ...)
    ↓
canonical PHP value
```

Key creation uses the same pipeline.

## 9.4 FieldType extensions

No new comparison or snapshot API will be introduced initially.

If Unit of Work testing proves that canonical PHP strict comparison is insufficient, optional interfaces may later be added:

```php
interface ComparableFieldTypeInterface
{
    public static function valuesEqual(
        mixed $left,
        mixed $right,
        FieldContext $field,
    ): bool;
}
```

```php
interface SnapshotFieldTypeInterface
{
    public static function snapshot(
        mixed $value,
        FieldContext $field,
    ): mixed;
}
```

These are deferred and must be justified by actual failing cases.

---

# 10. DataManager

## 10.1 Responsibility

`DataManager` is the main runtime entry point.

It connects:

* Registry definitions;
* FieldType Registry;
* Mapper gateway;
* database adapters;
* query creation;
* mutation creation;
* transactions;
* optional ORM sessions.

It does not wrap collections in another runtime collection class.

`getCollection()` returns the same `CollectionInterface` managed by the Registry.

## 10.2 Interface

```php
interface DataManagerInterface
{
    public function getRegistry(): RegistryInterface;

    public function getCollection(string $name): CollectionInterface;

    public function getView(string $name): ViewDefinitionInterface;

    public function query(
        string|QueryableDefinitionInterface $source,
    ): QueryInterface;

    public function find(
        string|CollectionInterface $collection,
        mixed $key,
    ): mixed;

    public function insert(
        string|CollectionInterface $collection,
    ): InsertInterface;

    public function update(
        string|CollectionInterface $collection,
    ): UpdateInterface;

    public function delete(
        string|CollectionInterface $collection,
    ): DeleteInterface;

    public function transaction(callable $callback): mixed;

    public function getDatabaseAdapter(
        string $database,
    ): DatabaseAdapterInterface;

    public function getMapper(): ConversionGateway;

    public function getSession(): SessionInterface;
}
```

`getSession()` belongs to the optional ORM package. The core DataManager interface may expose it through an extended ORM-specific interface rather than requiring the ORM package.

## 10.3 Definition identity

These must refer to the same definition object:

```php
$data->getCollection('users')
    === $data->getRegistry()->getCollection('users');
```

This permits:

```php
$key->getCollection() === $users;
```

within the same DataManager and Registry scope.

---

# 11. Query references and expressions

## 11.1 Separate query references from definitions

A collection field definition describes what a field is.

A query field reference describes that field in one query scope.

These are different objects.

This is required because the same collection may appear:

* as the root source;
* in a join;
* in a correlated subquery;
* multiple times under different aliases;
* through multiple relation paths.

## 11.2 SourceRef

Each query owns a root source reference.

```php
interface SourceRefInterface
{
    public function getDefinition(): QueryableDefinitionInterface;

    public function getField(string $name): FieldRefInterface;

    public function getRelation(string $name): RelationRefInterface;

    public function getPath(): string;

    public function getScopeId(): string;
}
```

Usage:

```php
$query = $data->query($users);
$user = $query->getReference();

$query
    ->select(
        $user->getField('id'),
        $user->getField('name'),
    )
    ->where(
        $user->getField('active')->equals(true)
    );
```

Generated or specialized query-reference classes may later provide:

```php
$user->id
$user->name
$user->posts
```

The required core API remains `getField()` and `getRelation()`.

## 11.3 FieldRef

```php
interface FieldRefInterface extends ExpressionInterface
{
    public function getField(): FieldInterface;

    public function getSource(): SourceRefInterface;

    public function getPath(): string;

    public function equals(mixed $value): ExpressionInterface;

    public function notEquals(mixed $value): ExpressionInterface;

    public function greaterThan(mixed $value): ExpressionInterface;

    public function greaterThanOrEqual(mixed $value): ExpressionInterface;

    public function lessThan(mixed $value): ExpressionInterface;

    public function lessThanOrEqual(mixed $value): ExpressionInterface;

    public function in(iterable|QueryInterface $values): ExpressionInterface;

    public function notIn(iterable|QueryInterface $values): ExpressionInterface;

    public function isNull(): ExpressionInterface;

    public function isNotNull(): ExpressionInterface;

    public function contains(string $value): ExpressionInterface;

    public function startsWith(string $value): ExpressionInterface;

    public function endsWith(string $value): ExpressionInterface;

    public function as(string $alias): SelectionInterface;
}
```

## 11.4 RelationRef

```php
interface RelationRefInterface
{
    public function getRelation(): RelationInterface;

    public function getSource(): SourceRefInterface;

    public function getTarget(): SourceRefInterface;

    public function getField(string $name): FieldRefInterface;

    public function getRelation(string $name): RelationRefInterface;

    public function exists(): ExpressionInterface;

    public function isEmpty(): ExpressionInterface;

    public function isNotEmpty(): ExpressionInterface;
}
```

Relation-specific reference subclasses may add extra query operations.

## 11.5 Expression hierarchy

```text
ExpressionInterface
├── FieldRef
├── LiteralExpression
├── ParameterExpression
├── ComparisonExpression
├── BooleanExpression
├── FunctionExpression
├── AggregateExpression
├── ExistsExpression
├── CaseExpression
├── QueryInterface as subquery
└── RawExpression
```

`ValueRef` is not part of this hierarchy.

`ValueRef` belongs to mutation execution.

Query literal values use `ParameterExpression` or an equivalent name.

## 11.6 Expression hub

The centralized expression hub is the canonical entry point for expressions that do not naturally belong to one field.

```php
interface ExpressionHubInterface
{
    public function value(mixed $value): ExpressionInterface;

    public function and(
        ExpressionInterface ...$expressions,
    ): ExpressionInterface;

    public function or(
        ExpressionInterface ...$expressions,
    ): ExpressionInterface;

    public function not(
        ExpressionInterface $expression,
    ): ExpressionInterface;

    public function count(
        ExpressionInterface|RelationRefInterface|string|null $expression = null,
        bool $distinct = false,
    ): AggregateExpressionInterface;

    public function sum(
        ExpressionInterface|string $expression,
    ): AggregateExpressionInterface;

    public function average(
        ExpressionInterface|string $expression,
    ): AggregateExpressionInterface;

    public function minimum(
        ExpressionInterface|string $expression,
    ): AggregateExpressionInterface;

    public function maximum(
        ExpressionInterface|string $expression,
    ): AggregateExpressionInterface;

    public function exists(
        RelationRefInterface|QueryInterface|string $source,
    ): ExpressionInterface;

    public function coalesce(
        ExpressionInterface|mixed ...$values,
    ): ExpressionInterface;

    public function function(
        string $name,
        ExpressionInterface|mixed ...$arguments,
    ): ExpressionInterface;

    public function raw(
        string $expression,
        array $parameters = [],
    ): ExpressionInterface;
}
```

The adapter determines which expression functions it can compile.

Raw expressions are an explicit escape hatch and must keep parameters separate from SQL text.

## 11.7 Query as subquery

A `QueryInterface` may be passed where a subquery expression is valid.

Example:

```php
$publishedPostIds = $data
    ->query($posts)
    ->select($post->getField('user_id'))
    ->where($post->getField('published')->equals(true));

$usersQuery->where(
    $user->getField('id')->in($publishedPostIds)
);
```

The compiler validates whether the query shape is valid for the destination:

* scalar subquery: exactly one selected expression;
* `IN` subquery: exactly one selected expression;
* derived-table query: any valid tabular shape;
* existence query: selection may be simplified.

No explicit `subquery()` wrapper is required.

---

# 12. QueryInterface

## 12.1 Public fluent API

```php
interface QueryInterface extends IteratorAggregate
{
    public function getDefinition(): QueryableDefinitionInterface;

    public function getReference(): SourceRefInterface;

    public function getSpec(): QuerySpec;

    public function select(
        ExpressionInterface|SelectionInterface|string ...$selections,
    ): static;

    public function addSelect(
        ExpressionInterface|SelectionInterface|string ...$selections,
    ): static;

    public function where(
        ExpressionInterface ...$expressions,
    ): static;

    public function orWhere(
        ExpressionInterface ...$expressions,
    ): static;

    public function whereKey(Key|array|string|int $key): static;

    public function with(
        string|RelationRefInterface $relation,
        ?callable $configure = null,
    ): static;

    public function groupBy(
        ExpressionInterface|string ...$expressions,
    ): static;

    public function having(
        ExpressionInterface ...$expressions,
    ): static;

    public function orderBy(
        ExpressionInterface|string $expression,
        string $direction = 'ASC',
    ): static;

    public function orderByDesc(
        ExpressionInterface|string $expression,
    ): static;

    public function distinct(bool $distinct = true): static;

    public function limit(?int $limit): static;

    public function offset(?int $offset): static;

    public function fetchAll(): array;

    public function fetchOne(): mixed;

    public function fetchColumn(
        string|int|null $column = null,
    ): array;

    public function exists(): bool;

    public function count(): int;
}
```

The exact mutation versus cloning behavior of fluent methods remains an implementation decision. It must be consistent across the query API.

## 12.2 Mapping

Query objects are valid mapper sources:

```php
$books = map($query)->to(Book::class);
```

No intermediate mapping query object is introduced.

Existing mapping forms remain available:

```php
map($query)
    ->using(CustomBookMapper::class)
    ->to(Book::class);
```

```php
map($query)
    ->as(WireRepresentation::class)
    ->toArray();
```

`map($query)->to(Book::class)` recognizes a Query as a collection source automatically.

The query is executed lazily when the mapper iterates it.

---

# 13. QuerySpec

## 13.1 Purpose

`QueryInterface` is the public fluent object.

`QuerySpec` is its normalized internal state.

The existing Overnight QuerySpec and node system should be extracted from the REST API namespace and generalized rather than replaced.

REST parsers may create the same QuerySpec.

Fluent queries create the same QuerySpec.

Relation handlers may create nested QuerySpecs.

## 13.2 Core QuerySpec

```php
final readonly class QuerySpec
{
    public function getDefinition(): QueryableDefinitionInterface;

    public function getSelections(): SelectionSet;

    public function getFilter(): ?ExpressionInterface;

    public function getGroupBy(): array;

    public function getHaving(): ?ExpressionInterface;

    public function getSort(): array;

    public function getPagination(): ?Pagination;

    public function getRelationLoads(): RelationLoadTree;

    public function isDistinct(): bool;
}
```

REST-specific concepts such as wire-level search syntax or requested meta fields must not become fundamental data-layer query concepts.

A REST parser converts:

* search into ordinary expressions;
* aggregate parameters into aggregate selections;
* requested meta into one or more ordinary query operations.

## 13.3 Query normalization

Normalization ensures:

* string field names become scoped `FieldRef` objects;
* relation paths are resolved;
* aliases are unique;
* literal values are normalized through FieldType;
* grouped queries are valid;
* aggregate selections are known;
* relation-load trees are normalized;
* internal key fields required by relation handlers are selected;
* user selections remain distinguishable from internal selections.

There is one normalized QuerySpec, not separate REST and ORM query structures.

---

# 14. Aggregates

## 14.1 Aggregates are expressions

Aggregates are not a separate query subsystem.

They are expression nodes that may appear in:

* selections;
* view fields;
* `HAVING`;
* ordering;
* correlated subqueries;
* relation-derived values.

```php
interface AggregateExpressionInterface extends ExpressionInterface
{
    public function getFunction(): string;

    public function getExpression(): ExpressionInterface|RelationRefInterface|null;

    public function isDistinct(): bool;

    public function getFilter(): ?ExpressionInterface;

    public function filter(
        ExpressionInterface $expression,
    ): static;

    public function as(string $alias): SelectionInterface;
}
```

## 14.2 Selected aggregate

```php
$query = $data->query($users);
$user = $query->getReference();

$query
    ->select(
        $user->getField('country'),
        x()->count($user->getField('id'))->as('user_count'),
    )
    ->groupBy(
        $user->getField('country')
    );
```

## 14.3 Relation aggregate

```php
$query->select(
    $user->getField('id'),
    $user->getField('name'),
    x()->count(
        $user->getRelation('posts')
    )->as('post_count'),
);
```

The planner may implement this as:

* a JOIN and GROUP BY;
* a correlated subquery;
* a pre-aggregated derived table;
* an adapter-specific strategy.

The view/query expression defines the meaning. The planner selects the execution strategy.

## 14.4 View aggregate

```php
$registry
    ->view('user_summary')
        ->source('users')

        ->field('post_count', 'integer')
            ->expression(
                x()->count('posts')
            )
            ->end()
        ->end();
```

When the view is queried, the aggregate expression is expanded into the query’s own alias and relation scope.

View definition expressions must therefore use logical definition paths, not hardcoded SQL aliases.

## 14.5 Terminal aggregate

```php
$count = $query->count();
```

This is an execution convenience.

It creates an aggregate query derived from the current QuerySpec while removing irrelevant ordering and preserving relevant filters and grouping semantics.

---

# 15. Relation definitions and handlers

## 15.1 Relation classes

The current relation-class architecture remains.

Conceptually:

```php
$collection->relation(
    'author',
    BelongsToRelation::class,
);
```

Convenience methods such as existing `belongsTo()`, `hasOne()`, and `hasMany()` remain.

The Registry stores:

```php
[
    'class' => BelongsToRelation::class,
    // Existing relation data.
]
```

## 15.2 Composite relation keys

Relation key definitions must accept ordered arrays.

Conceptual metadata:

```php
[
    'innerKey' => ['tenant_id', 'account_id'],
    'outerKey' => ['tenant_id', 'id'],
]
```

Or the equivalent structure already used by Overnight.

Validation requires equal arity and compatible FieldTypes.

No relation handler may assume one inner or outer key.

## 15.3 Relation handler registration

```php
interface RelationHandlerRegistryInterface
{
    /**
     * @param class-string<RelationInterface> $relationClass
     * @param class-string<RelationQueryHandlerInterface> $handlerClass
     */
    public function registerQueryHandler(
        string $relationClass,
        string $handlerClass,
    ): void;

    public function getQueryHandler(
        RelationInterface $relation,
    ): RelationQueryHandlerInterface;

    /**
     * Optional mutation support.
     */
    public function registerMutationHandler(
        string $relationClass,
        string $handlerClass,
    ): void;
}
```

A relation definition class may alternatively expose its own handler class. The project should select one consistent registration mechanism.

## 15.4 RelationQueryHandler

The query handler follows the same general model as the current Overnight relation handlers.

```php
interface RelationQueryHandlerInterface
{
    /**
     * Inspect the requested relation load before the root query runs.
     *
     * The handler may request internal source fields, register aliases,
     * or modify the root execution plan when using a joined strategy.
     */
    public function prepare(
        RelationLoadContext $context,
    ): void;

    /**
     * Load or parse the related records after the parent records exist.
     */
    public function load(
        RelationLoadContext $context,
        array $parentRecords,
    ): RelationLoadResult;

    /**
     * Attach loaded values to parent result records.
     */
    public function attach(
        RelationLoadContext $context,
        array &$parentRecords,
        RelationLoadResult $result,
    ): void;
}
```

This interface may be refined during extraction from the current handlers, but its three responsibilities must remain separate:

1. prepare required data;
2. load or parse related data;
3. attach relation results.

## 15.5 Handler-specific strategies

A handler may implement its relation using:

* a JOIN in the root query;
* a second batched query;
* multiple queries;
* a correlated subquery;
* a lateral query where supported;
* an external source;
* custom application logic.

Generic Query code does not choose the strategy itself.

The relation handler knows:

* relation cardinality;
* key mappings;
* target definition;
* required internal fields;
* how to group child rows;
* how to attach the result;
* whether nested relations are supported;
* whether limits or ordering can be applied per parent.

---

# 16. Relation loading and `with()`

## 16.1 Meaning of `with()`

In this project:

```php
$query->with('posts');
```

means:

> Load the relation and include it in the result representation.

It does not mean:

> Force an SQL JOIN.

The query stores the requested load semantically.

## 16.2 RelationLoadTree

```php
final class RelationLoadTree
{
    /**
     * @return list<RelationLoadNode>
     */
    public function getNodes(): array;

    public function add(RelationLoadNode $node): void;

    public function get(string $path): ?RelationLoadNode;
}
```

```php
final class RelationLoadNode
{
    public function getRelation(): RelationInterface;

    public function getQuerySpec(): QuerySpec;

    public function getResponseName(): string;

    public function getChildren(): RelationLoadTree;

    public function getStrategyHint(): ?string;
}
```

The node contains no Cycle query, Doctrine query, or SQL string.

## 16.3 Configured relation load

```php
$query->with(
    'posts',
    static function (RelationQueryInterface $posts): void {
        $post = $posts->getReference();

        $posts
            ->select(
                $post->getField('id'),
                $post->getField('title'),
            )
            ->where(
                $post->getField('published')->equals(true)
            )
            ->orderByDesc(
                $post->getField('published_at')
            )
            ->with('comments');
    },
);
```

The callback configures a nested QuerySpec for the relation target.

The nested QuerySpec may contain:

* selections;
* filters;
* sorting;
* nested relation loads;
* relation-specific options;
* limits where supported by the handler.

## 16.4 Execution sequence

For:

```php
$data
    ->query($users)
    ->with('posts.comments')
    ->fetchAll();
```

the execution process is:

```text
1. Build root QuerySpec.
2. Build RelationLoadTree.
3. Create relation handlers for each load node.
4. Run handler prepare() from parent to child.
5. Compile and execute the root query.
6. Convert root database values through FieldType.
7. Invoke root relation handlers.
8. Each handler chooses joined parsing or separate loading.
9. Recursively load nested relations.
10. Attach relation results to parent records.
11. Remove internal-only selected fields.
12. Return the assembled PHP representation.
```

## 16.5 Filtering through relations

Loading and filtering are independent.

This:

```php
$query->with('posts');
```

loads posts.

This:

```php
$query->where(
    $user
        ->getRelation('posts')
        ->getField('published')
        ->equals(true)
);
```

filters users based on posts.

The compiler or relation query handler may implement the filter using:

* `EXISTS`;
* JOIN;
* subquery;
* another relation-specific operation.

Both may be combined:

```php
$query
    ->where(
        $user
            ->getRelation('posts')
            ->getField('flagged')
            ->equals(true)
    )
    ->with(
        'posts',
        static fn (RelationQueryInterface $posts) =>
            $posts->where(
                $posts
                    ->getReference()
                    ->getField('published')
                    ->equals(true)
            )
    );
```

This means:

> Return users having flagged posts, while loading their published posts.

## 16.6 Explicit SQL joins

An explicit `join()` method may exist for advanced tabular queries.

It is not the mechanism behind normal `with()` relation loading.

---

# 17. Query planning and execution

## 17.1 QueryExecutor

```php
interface QueryExecutorInterface
{
    public function execute(QuerySpec $spec): iterable;
}
```

Responsibilities:

* resolve the database adapter;
* create relation handlers;
* allow handlers to prepare the root query;
* compile the root database query;
* execute it;
* apply FieldType conversion;
* execute relation loads;
* assemble nested result data;
* remove internal-only fields.

## 17.2 QueryExecutionContext

```php
interface QueryExecutionContextInterface
{
    public function getDataManager(): DataManagerInterface;

    public function getSpec(): QuerySpec;

    public function getAdapter(): DatabaseAdapterInterface;

    public function getAliasRegistry(): AliasRegistryInterface;

    public function requireInternalField(
        FieldRefInterface $field,
    ): void;

    public function getRelationHandler(
        RelationLoadNode $node,
    ): RelationQueryHandlerInterface;
}
```

Logical field references should not contain final SQL aliases.

The adapter compiler assigns physical aliases through the query-scoped AliasRegistry.

## 17.3 No premature optimizer

Version 1 requires a planner only to the extent needed to:

* expand views;
* resolve paths;
* coordinate relation handlers;
* preserve root pagination;
* choose portable relation-loading strategies;
* compile expressions.

It does not need a general cost-based SQL optimizer.

---

# 18. Database adapters

## 18.1 Purpose

The project owns:

* semantic queries;
* definitions;
* expression AST;
* relation-load semantics;
* mapping;
* mutation plans.

The selected DBAL owns:

* connections;
* parameter binding;
* SQL generation;
* quoting;
* dialect differences;
* transactions;
* driver interaction;
* low-level schema inspection where needed.

## 18.2 Adapter interface

```php
interface DatabaseAdapterInterface
{
    public function getQueryCompiler(): QueryCompilerInterface;

    public function getMutationCompiler(): MutationCompilerInterface;

    public function fetchAll(
        CompiledQueryInterface $query,
    ): array;

    public function fetchOne(
        CompiledQueryInterface $query,
    ): mixed;

    public function execute(
        CompiledMutationInterface $mutation,
    ): DatabaseMutationResultInterface;

    public function transaction(callable $callback): mixed;
}
```

```php
interface QueryCompilerInterface
{
    public function compile(
        QuerySpec $spec,
        QueryCompilationContext $context,
    ): CompiledQueryInterface;
}
```

```php
interface MutationCompilerInterface
{
    public function compile(
        OperationInterface $operation,
        MutationCompilationContext $context,
    ): CompiledMutationInterface;
}
```

Compiled objects wrap the underlying Cycle or Doctrine query without exposing them to core components.

## 18.3 No broad DatabaseCapabilities object

Version 1 will not define:

```php
DatabaseCapabilities
```

The adapter internally uses its DBAL’s platform or driver information.

When an advanced core feature genuinely requires capability negotiation, introduce the narrowest possible contract.

Examples:

```php
interface ReturningMutationCompilerInterface
```

or:

```php
$adapter->supports(QueryFeature::LATERAL_JOIN);
```

This should not be added until a real planner branch needs it.

## 18.4 Portable first strategies

Initial query and relation handlers should prioritize portable strategies:

* standard JOINs;
* ordinary subqueries;
* batched `WHERE IN`;
* GROUP BY;
* transactions;
* generated-ID retrieval through the DBAL.

Advanced platform-specific strategies can be adapter extensions.

## 18.5 Initial adapters

Expected packages:

```text
data-core
data-adapter-cycle
data-adapter-doctrine
data-orm
```

The first implementation should use Cycle Database because it is already understood by the Overnight codebase.

The Doctrine DBAL adapter should be developed after the core abstractions prove that they do not leak Cycle types.

---

# 19. Existing Mapper integration

## 19.1 Query as mapping input

```php
$query = $data
    ->query($books)
    ->where(
        $book->getField('published')->equals(true)
    );

$objects = map($query)->to(Book::class);
```

The mapping pipeline receives:

* iterable query results;
* the source collection or view definition;
* field contexts;
* the source representation;
* loaded field metadata;
* nested relation data.

## 19.2 No external MappingQuery class

The following is rejected:

```php
$query->map()->to(Book::class);
```

The canonical system remains:

```php
map($query)->to(Book::class);
```

This keeps all structural mapping in the existing Mapper subsystem.

## 19.3 Automatic collection detection

A Query is inherently a collection source.

`map($query)` should set collection mapping automatically.

The current explicit form remains available for ordinary arrays:

```php
map($rows)
    ->collection()
    ->to(Book::class);
```

## 19.4 Result forms

Without mapping:

```php
$rows = $query->fetchAll();
```

returns arrays in canonical PHP representation.

With mapping:

```php
$books = map($query)->to(Book::class);
```

returns mapped objects.

Other existing forms remain:

```php
map($query)->toArray();
map($query)->toJson();
map($query)->using(CustomMapper::class)->to(Book::class);
```

## 19.5 ORM entity mapping

The optional ORM provides an Entity mapper or resolver integrated into the same map system.

It must not create a second object hydration framework.

---

# 20. Direct mutations

## 20.1 Public mutation builders

```php
$result = $data
    ->insert($users)
    ->values([
        'name' => 'Guilherme',
        'email' => 'gui@example.com',
    ])
    ->execute();
```

```php
$result = $data
    ->update($users)
    ->whereKey($users->createKey(10))
    ->set([
        'name' => 'Guilherme Aiolfi',
    ])
    ->execute();
```

```php
$result = $data
    ->delete($users)
    ->whereKey($users->createKey(10))
    ->execute();
```

Bulk predicates may also be supported:

```php
$data
    ->update($users)
    ->where(
        $user->getField('active')->equals(false)
    )
    ->set([
        'archived' => true,
    ])
    ->execute();
```

## 20.2 Mutation builder interfaces

```php
interface InsertInterface
{
    public function values(array|object $values): static;

    public function returning(string ...$fields): static;

    public function execute(): MutationResultInterface;
}
```

```php
interface UpdateInterface
{
    public function set(array $values): static;

    public function where(
        ExpressionInterface ...$expressions,
    ): static;

    public function whereKey(Key|array|string|int $key): static;

    public function returning(string ...$fields): static;

    public function execute(): MutationResultInterface;
}
```

```php
interface DeleteInterface
{
    public function where(
        ExpressionInterface ...$expressions,
    ): static;

    public function whereKey(Key|array|string|int $key): static;

    public function returning(string ...$fields): static;

    public function execute(): MutationResultInterface;
}
```

## 20.3 MutationResult

```php
interface MutationResultInterface
{
    public function getAffectedRows(): int;

    public function getKey(): ?Key;

    public function getReturnedValues(): array;

    public function getReturnedValue(string $field): mixed;
}
```

## 20.4 Field conversion

Mutation input is canonicalized through FieldType before execution.

Unknown fields, read-only fields, and invalid representations are rejected before reaching the DBAL.

---

# 21. ValueRef and mutation dependencies

## 21.1 Purpose

`ValueRef` represents a value that is not available yet but will be produced by another mutation state or operation.

Typical example:

```text
Insert parent
    ↓ generates parent.id
Insert child using parent.id
```

## 21.2 Generalized ValueSourceInterface

```php
interface ValueSourceInterface
{
    public function isValueReady(string $field): bool;

    public function getValue(string $field): mixed;
}
```

## 21.3 ValueRef

```php
final readonly class ValueRef
{
    public function __construct(
        private ValueSourceInterface $source,
        private string $field,
    ) {
    }

    public static function forSourceField(
        ValueSourceInterface $source,
        string $field,
    ): self {
        return new self($source, $field);
    }

    public function getSource(): ValueSourceInterface
    {
        return $this->source;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function isReady(): bool
    {
        return $this->source->isValueReady($this->field);
    }

    public function getValue(): mixed
    {
        return $this->source->getValue($this->field);
    }
}
```

Changes from the current class:

* moved out of the REST API namespace;
* no dependency on REST `NodeStateInterface`;
* source generalized to `ValueSourceInterface`;
* `resolve()` renamed to `getValue()`;
* factory renamed to reflect the generalized source.

## 21.4 ValueRef is mutation-only

Do not use `ValueRef` for:

* query fields;
* SQL parameters;
* view paths;
* aliases.

Those use:

* `FieldRef`;
* `RelationRef`;
* `ParameterExpression`;
* expression nodes.

## 21.5 Composite deferred keys

A composite child foreign key may contain multiple references:

```php
[
    'tenant_id' => new ValueRef($parentState, 'tenant_id'),
    'order_id' => new ValueRef($parentState, 'id'),
]
```

Each component is resolved independently.

A `Key` is created only when every primary-key component is ready.

## 21.6 Operation graph

```php
interface OperationInterface extends ValueSourceInterface
{
    public function isReady(): bool;

    public function execute(
        OperationExecutionContext $context,
    ): void;

    /**
     * @return iterable<ValueRef>
     */
    public function getDependencies(): iterable;
}
```

```php
interface MutationPlanInterface
{
    /**
     * @return list<OperationInterface>
     */
    public function getOperations(): array;
}
```

```php
interface OperationExecutorInterface
{
    public function execute(
        MutationPlanInterface $plan,
    ): MutationResultInterface;
}
```

The executor:

1. finds ready operations;
2. resolves available `ValueRef` values;
3. executes operations;
4. records returned/generated values;
5. unlocks dependent operations;
6. detects unresolvable cycles;
7. runs inside a transaction when required.

This generalizes the useful part of the current REST mutation architecture.

---

# 22. Relation mutations

## 22.1 No generic relation connect API

The core will not require:

```php
$record->relation('tags')->connect($key);
```

Generic ORM code cannot assume every relation supports connect/disconnect semantics.

## 22.2 Relation-specific conveniences

A many-to-many runtime relation may expose:

```php
$tags->connect($tagKey);
$tags->disconnect($tagKey);
```

only because that relation class and its mutation handler support those operations.

Another custom relation may expose entirely different operations.

## 22.3 Relation mutation handler

```php
interface RelationMutationHandlerInterface
{
    public function plan(
        RelationMutationContext $context,
    ): MutationPlanInterface;
}
```

The handler receives:

* relation definition;
* source state;
* target state or key;
* requested change;
* mutation context.

It may produce:

* foreign-key updates;
* pivot inserts;
* pivot deletes;
* custom operations;
* no operation when unsupported.

## 22.4 REST and ORM reuse

A nested REST mutation parser may produce relation changes.

The Unit of Work may detect relation changes.

Both are passed to the same relation mutation handler.

The core mutation planner does not know whether the change originated from REST, an entity, or direct application code.

---

# 23. Optional ORM and Unit of Work

## 23.1 Layering

The ORM package is built on the data core.

It reuses:

* Registry;
* collections;
* views;
* Key;
* Query;
* map();
* FieldType;
* relations;
* direct mutations;
* ValueRef;
* operation plans;
* transactions.

## 23.2 Session

```php
interface SessionInterface
{
    public function find(
        string $mapping,
        mixed $key,
    ): ?object;

    public function query(
        string $mapping,
    ): QueryInterface;

    public function persist(object $entity): void;

    public function remove(object $entity): void;

    public function contains(object $entity): bool;

    public function refresh(object $entity): void;

    public function flush(): void;

    public function clear(?string $mapping = null): void;

    public function transaction(callable $callback): mixed;
}
```

## 23.3 EntityMapping

An entity mapping connects:

* an object class or representation;
* a read collection or view;
* a writable collection;
* identity fields;
* field/property mappings;
* relation mappings;
* lifecycle behavior.

```php
interface EntityMappingInterface
{
    public function getName(): string;

    public function getClass(): string;

    public function getReadDefinition(): QueryableDefinitionInterface;

    public function getWriteCollection(): CollectionInterface;

    public function getIdentityFields(): array;

    public function getFieldMapping(
        string $field,
    ): EntityFieldMappingInterface;

    public function getRelationMapping(
        string $relation,
    ): EntityRelationMappingInterface;
}
```

This permits:

```text
Read from: user_summary view
Write to: users collection
Map to: User class
```

## 23.4 IdentityMap

```php
interface IdentityMapInterface
{
    public function has(
        string $mapping,
        Key $key,
    ): bool;

    public function get(
        string $mapping,
        Key $key,
    ): ?object;

    public function put(
        string $mapping,
        Key $key,
        object $entity,
    ): void;

    public function remove(
        string $mapping,
        Key $key,
    ): void;

    public function clear(?string $mapping = null): void;
}
```

The mapping name is part of the identity-map address so multiple entity representations may exist for one collection row.

Physical-row write conflicts between mappings must eventually be detected at collection-Key level.

## 23.5 EntityState

```php
interface EntityStateInterface extends ValueSourceInterface
{
    public function getEntity(): object;

    public function getMapping(): EntityMappingInterface;

    public function getKey(): ?Key;

    public function getStatus(): EntityStatus;

    public function getLoadedFields(): array;

    public function getOriginalValues(): array;

    public function getCurrentValues(): array;

    public function getChanges(): array;
}
```

The state distinguishes:

* field not loaded;
* field loaded as `null`;
* field changed to `null`;
* field unchanged;
* generated field pending;
* relation unloaded;
* relation loaded and empty.

## 23.6 UnitOfWork

```php
interface UnitOfWorkInterface
{
    public function registerNew(
        object $entity,
        EntityMappingInterface $mapping,
    ): void;

    public function registerManaged(
        object $entity,
        EntityMappingInterface $mapping,
        array $loadedValues,
        ?Key $key,
    ): void;

    public function registerRemoved(
        object $entity,
    ): void;

    public function getState(
        object $entity,
    ): EntityStateInterface;

    public function getChangeSets(): array;

    public function commit(): void;

    public function clear(): void;
}
```

Commit pipeline:

```text
Tracked entities
    ↓
Field extraction through Mapper
    ↓
FieldType canonicalization
    ↓
Change detection
    ↓
ChangeSets
    ↓
Relation mutation handlers
    ↓
MutationPlan
    ↓
OperationExecutor
    ↓
Database adapter transaction
```

## 23.7 Mapping integration

Entity hydration uses the existing mapper:

```php
map($query)
    ->using(
        EntityMapper::class,
        $session,
        $mapping,
    )
    ->to(User::class);
```

The Session may provide convenience APIs, but the implementation must use the same mapping system rather than a second hydrator.

## 23.8 Classless entities

The data core already supports arrays.

The ORM should eventually support a standard mutable record representation in addition to application objects.

That representation must use the same:

* Key;
* field definitions;
* loaded-field tracking;
* relation tracking;
* Unit of Work.

Plain arrays are suitable query results but cannot be reliably tracked by object identity without a wrapper.

The exact standard Record API is deferred.

---

# 24. Extension points

The standalone project must support registration of:

## 24.1 Field types

Through the existing FieldType Registry.

## 24.2 Representations

Through the existing Representation system.

## 24.3 Structural mappers

Through the existing Mapper registry and `map()`.

## 24.4 Relation definitions

```php
$registry->relationType(
    CustomRelation::class,
    CustomRelationQueryHandler::class,
    CustomRelationMutationHandler::class,
);
```

The exact registration location is open, but the relation must remain class-based.

## 24.5 Expression compilers

```php
interface ExpressionCompilerInterface
{
    public function supports(
        ExpressionInterface $expression,
    ): bool;

    public function compile(
        ExpressionInterface $expression,
        QueryCompilationContext $context,
    ): mixed;
}
```

Adapter packages register compilers for:

* standard expressions;
* date functions;
* JSON functions;
* database-specific functions;
* custom application functions.

## 24.6 View resolvers

Application-computed view fields use registered resolver classes.

## 24.7 Database adapters

Cycle and Doctrine adapters remain separate packages.

## 24.8 Entity mapping sources

Potential future sources:

* fluent mapping definitions;
* PHP attributes;
* generated mappings;
* external arrays.

All must eventually produce the same EntityMapping model.

The Registry remains authoritative at runtime.

---

# 25. Caching

## 25.1 Included initially

The first implementation supports caching of:

* the Registry master array;
* optional generated class metadata;
* mapper configuration where already supported.

## 25.2 Deferred

The first implementation does not include:

* query-result caching;
* second-level entity caching;
* relation-result caching;
* automatic invalidation graphs.

Potential future additions:

* normalized query-plan cache;
* compiled adapter-query cache;
* entity cache;
* dependency-tagged query cache.

Cache design must not distort the initial query and identity APIs.

---

# 26. Explicitly rejected designs

The first architecture rejects:

* a compiled Registry parallel to the actual definition;
* `ModelConfig` and `CollectionConfig`;
* a new `DataType` system;
* a separate `MappingQuery`;
* forwarding parent methods through fields;
* removing `end()` from the current definition DSL;
* field-level primary-key declarations;
* generic relation `connect()` and `disconnect()` methods;
* assuming `with()` means SQL JOIN;
* putting Cycle or Doctrine query objects into QuerySpec;
* implementing database dialects directly;
* query-result caching in the first pass;
* making REST payload structures part of the core data model;
* allowing unresolved `ValueRef` objects inside resolved `Key` objects.

---

# 27. Initial implementation stages

## Stage 1 — Extract and stabilize definitions

Deliver:

* standalone Registry;
* one master definition array;
* current Collection, Field, and Relation APIs;
* `end()` behavior;
* Registry cache round trip;
* collection-level `primaryKey()`;
* `Key`;
* Registry validation;
* existing FieldType and Mapper integration.

No query execution is required yet.

## Stage 2 — Query core

Deliver:

* QueryInterface;
* extracted/generalized QuerySpec;
* SourceRef;
* FieldRef;
* RelationRef;
* expression AST;
* expression hub;
* selections;
* filters;
* sorting;
* grouping;
* aggregate expressions;
* pagination;
* subqueries.

Use an in-memory fake compiler for unit tests before the first DB adapter.

## Stage 3 — Views

Deliver:

* ViewDefinition;
* ViewField;
* direct source fields;
* renamed fields;
* expression fields;
* aggregate fields;
* explicit to-one and to-many semantics;
* view identity;
* view expansion into QuerySpec;
* read-only execution.

Application resolvers may be included after database expressions work.

## Stage 4 — Cycle Database adapter

Deliver:

* query compiler;
* parameter binding;
* FieldType database conversion;
* select execution;
* transactions;
* simple insert/update/delete;
* generated-field retrieval;
* MySQL, PostgreSQL, and SQLite integration tests where practical.

## Stage 5 — Relation loading

Deliver:

* RelationLoadTree;
* handler registry;
* handler prepare/load/attach lifecycle;
* nested relations;
* standard belongs-to;
* has-one;
* has-many;
* many-to-many;
* composite relation keys;
* JOIN and batched `WHERE IN` strategies;
* pagination-safe to-many loading.

## Stage 6 — Mapping query results

Deliver:

```php
map($query)->to(Book::class);
```

including:

* automatic collection mapping;
* nested relation data;
* FieldType conversion;
* arrays;
* objects;
* DTOs;
* existing representations.

## Stage 7 — Mutation plan

Deliver:

* direct mutation builders;
* ValueSourceInterface;
* generalized ValueRef;
* operations;
* operation dependency scheduling;
* generated values;
* MutationResult;
* transaction execution;
* composite-key mutation tests.

Relation mutation handlers may begin with standard relation types.

## Stage 8 — ORM module

Deliver:

* entity mappings;
* Session;
* IdentityMap;
* EntityState;
* loaded-field masks;
* change detection;
* UnitOfWork;
* relation change tracking;
* read from views;
* write to collections;
* multiple entity mappings per collection.

## Stage 9 — Doctrine DBAL adapter

Use the second adapter to prove that:

* QuerySpec is independent;
* expression AST is independent;
* relation handlers do not leak Cycle;
* mutation operations do not leak Cycle;
* FieldType remains the application conversion authority.

---

# 28. Required acceptance scenarios

## Definitions

1. Existing Overnight-style collection definitions continue working with `end()`.
2. Registry can be converted to an array and reconstructed without loss.
3. Relations remain concrete pluggable classes.
4. Custom relation classes can register handlers without modifying core code.

## Keys

5. Create and compare a simple integer key.
6. Normalize string `'10'` into integer `10`.
7. Create and compare a composite key.
8. Reject missing composite-key components.
9. Reject unexpected composite-key components.
10. Preserve declared composite-key order.
11. Extract keys from arrays and mapped objects.
12. Produce an unambiguous stable hash.

## Queries

13. Select fields from a collection.
14. Filter using FieldRef expressions.
15. Use the same collection twice in one query without alias collisions.
16. Use a Query directly as an `IN` subquery.
17. Select an aggregate and group the result.
18. Filter grouped data through `HAVING`.
19. Count a related collection.
20. Use a view aggregate field in filtering and ordering.

## Views

21. Query a view containing direct source fields.
22. Query a renamed field.
23. Query a field reached through a to-one relation.
24. Query a nested to-many relation.
25. Query an aggregate field.
26. Reject ambiguous to-many scalar traversal.
27. Map a view result to a DTO.
28. Distinguish identified and unidentified views.

## Relations

29. Load belongs-to using a JOIN.
30. Load has-many using one batched query.
31. Load nested relations.
32. Preserve root pagination when loading to-many relations.
33. Filter parents through a relation without necessarily loading it.
34. Load a relation with different filters from the parent filter.
35. Load relations with composite inner and outer keys.
36. Load a custom relation through a custom handler.

## Mapping

37. Execute `map($query)->to(Book::class)`.
38. Use existing FieldType conversion during hydration.
39. Map nested relation arrays.
40. Map a query to arrays and JSON through existing Mapper methods.

## Mutations

41. Insert and receive a generated simple key.
42. Insert a parent and use its generated key in a child operation through ValueRef.
43. Resolve multiple ValueRefs for a composite foreign key.
44. Update by simple Key.
45. Update by composite Key.
46. Delete by composite Key.
47. Roll back all operations when one dependency fails.
48. Detect an impossible operation dependency cycle.

## ORM

49. Return the same object for the same mapping and Key in one Session.
50. Permit two different mappings for the same collection row.
51. Prevent unloaded fields from being written as null.
52. Flush changes through the same mutation planner used by direct writes.
53. Read an entity from a View and write it to a Collection.
54. Track composite-key entities.
55. Persist a graph containing generated and composite keys.

---

# 29. Decisions still requiring confirmation

The architecture is sufficiently defined to begin discussion, but these API decisions should be settled before implementation.

## 29.1 Exact ViewDefinition DSL

The required concepts are known:

* source;
* identity;
* direct field path;
* expression;
* aggregate;
* one;
* many;
* application resolver.

The exact method names are not yet final.

## 29.2 View identity inheritance

Decision required:

* should identity automatically inherit from the source whenever possible;
* or should every trackable View explicitly call `identity()`?

## 29.3 Query-reference ergonomics

The baseline is:

```php
$query->getReference()->getField('name');
```

Possible later conveniences:

```php
$query->getReference()->name;
```

or generated typed references:

```php
$user->name;
```

The convenience must not replace the explicit core API.

## 29.4 Mutable or immutable Query

Both can present the same fluent API.

The project must choose one consistent behavior before users begin reusing query instances.

## 29.5 Entity mapping authority

Still unresolved:

* fluent entity mapping;
* class/attribute-derived mapping;
* generated classes from definitions;
* or multiple supported loaders with one explicit authority per mapping.

This decision belongs to the ORM stage and does not block the data core.

## 29.6 First adapter

Cycle Database is the likely first implementation.

The architecture should still be reviewed against Doctrine DBAL before adapter interfaces are frozen.

---

# 30. Expected end result

At the end of the core implementation, application code should be able to define persistent collections using the existing Overnight DSL:

```php
$registry
    ->collection('users')
        ->table('users')
        ->primaryKey('id')
        ->field('id', 'integer')->end()
        ->field('name', 'string')->end()
        ->field('email', 'string')->end()
    ->end();
```

Define business models:

```php
$registry
    ->view('user_summary')
        ->source('users')
        ->identity('id')
        ->field('id', 'integer')->from('id')->end()
        ->field('name', 'string')->from('name')->end()
        ->field('post_count', 'integer')
            ->expression(x()->count('posts'))
            ->end()
    ->end();
```

Query collections and views fluently:

```php
$query = $data->query('user_summary');
$user = $query->getReference();

$query
    ->select(
        $user->getField('id'),
        $user->getField('name'),
        $user->getField('post_count'),
    )
    ->where(
        $user->getField('post_count')->greaterThan(5)
    )
    ->with('posts')
    ->orderByDesc(
        $user->getField('post_count')
    );
```

Map results using the existing Mapper:

```php
$users = map($query)->to(UserSummary::class);
```

Use simple and composite identities through one class:

```php
$userKey = $usersCollection->createKey(10);

$pivotKey = $postUsers->createKey([
    'post_id' => 10,
    'user_id' => 4,
]);
```

Perform direct mutations:

```php
$data
    ->update($usersCollection)
    ->whereKey($userKey)
    ->set([
        'name' => 'Guilherme Aiolfi',
    ])
    ->execute();
```

And, when using the ORM package:

```php
$user = $session->find('users.domain', $userKey);

$user->rename('Guilherme Aiolfi');

$session->flush();
```

All of these operations must share:

* the same Registry definitions;
* the same FieldTypes;
* the same Representations;
* the same Mapper;
* the same Key;
* the same relations;
* the same query expressions;
* the same database adapters;
* the same mutation operations.

That shared foundation is the defining purpose of the project.
