# Implementation Task — Phase 5: Add `ViewDefinition` and Internalize Definition Hydration

## Objective

Introduce the structural foundation for application/business views while preserving the completed collection definition and primary-key systems.

This phase must:

1. Introduce a common definition contract shared by collections and views.
2. Add `ViewDefinition`, `ViewDefinitionInterface`, and `ViewField`.
3. Add Registry support for views.
4. Allow views to own fields, relations, and metadata through the same master-array architecture.
5. Generalize Field and Relation parent references from Collection-only to definition-level parents.
6. Preserve the existing fluent `->end()` style.
7. Remove `Collection::bindDefinitionArray()` from the public API.
8. Contain array-reference hydration behind internal construction infrastructure.
9. Remove or internalize public hydration constructor parameters where practical.
10. Preserve all Phase 1–4 behavior outside the explicitly changed parent and construction contracts.

This phase is structural only.

Do not implement view expressions, aggregates, query execution, relation loading, persistence, or writable views.

---

# 1. Required starting state

Before modifying production code:

1. Inspect the current repository and working tree.
2. Read:

   * `docs/phase-1-notes.md`
   * `docs/phase-2-notes.md`
   * `docs/phase-3-notes.md`
   * `docs/phase-3-storage-format.md`
   * `docs/phase-4-notes.md`
   * `docs/phase-4-key-format.md`
   * the current public API inventory
3. Run all existing quality commands.
4. Confirm all Phase 1–4 tests pass.
5. Confirm PHPStan passes at level 1.
6. Commit Phase 4 before starting this phase.

The Phase 4 notes state that its implementation is uncommitted because unrelated user-owned documentation changes exist under:

```text
docs/1-definitions/
```

Do not modify, delete, overwrite, or include unrelated user-owned changes merely to obtain a clean commit.

Use one of these safe approaches:

* stage and commit only Phase 4 implementation files;
* temporarily stash unrelated documentation changes;
* preserve them in a separate commit when they are ready.

Record:

* Phase 4 ending commit;
* Phase 5 starting commit;
* unrelated working-tree changes intentionally excluded.

Create:

```text
docs/phase-5-notes.md
```

Do not begin production changes without a committed Phase 4 baseline.

---

# 2. Scope

The Registry currently manages only collections:

```text
Registry
└── collections
    └── Collection
        ├── FieldMap
        └── RelationMap
```

After this phase:

```text
Registry
├── collections
│   └── Collection
│       ├── FieldMap
│       └── RelationMap
└── views
    └── ViewDefinition
        ├── FieldMap
        └── RelationMap
```

Collection and ViewDefinition are both definition roots.

They share:

* name;
* Registry;
* fields;
* relations;
* metadata;
* fluent `end()` behavior;
* master-array storage;
* wrapper reconstruction;
* custom subclass support.

They differ in purpose:

* Collection describes persisted data.
* ViewDefinition describes application/business data.

This phase does not yet define how a view field obtains or computes its value.

---

# 3. Explicit non-goals

Do not implement:

* query objects;
* QuerySpec;
* SourceRef;
* FieldRef;
* RelationRef;
* expression trees;
* expression hubs;
* aggregate expressions;
* view field `from()`;
* view field `expression()`;
* `one()`;
* `many()`;
* `explode()`;
* view resolvers;
* view identity;
* writable views;
* view-to-collection write mapping;
* relation query handlers;
* relation mutation handlers;
* database adapters;
* SQL;
* FieldType execution;
* Mapper execution;
* `map($query)->to(...)`;
* persistence;
* `ValueRef`;
* mutation planning;
* Unit of Work;
* identity map;
* REST or GraphQL integration;
* schema migrations;
* query or entity caching.

Do not begin Phase 6 automatically.

---

# 4. Definition naming and namespace

Use:

```php
ON\Data\Definition\DefinitionInterface
```

for the shared contract.

Add:

```text
src/Definition/View/ViewDefinition.php
src/Definition/View/ViewDefinitionInterface.php
src/Definition/View/ViewField.php
```

Namespaces:

```php
ON\Data\Definition\View\ViewDefinition
ON\Data\Definition\View\ViewDefinitionInterface
ON\Data\Definition\View\ViewField
```

Do not call the common contract:

```text
ModelConfig
CollectionConfig
CompiledDefinition
DataModel
```

The Registry definition remains the source of truth.

---

# 5. Shared `DefinitionInterface`

Introduce the minimum common contract required by Collection and ViewDefinition.

Derive the exact method signatures from the current Collection API.

Conceptual contract:

```php
namespace ON\Data\Definition;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Field\FieldMap;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\Relation\RelationMap;

interface DefinitionInterface
{
    public function getName(): string;

    public function getRegistry(): Registry;

    public function field(
        string $name,
        ?string $type = null,
    ): FieldInterface;

    public function getField(
        string $name,
    ): ?FieldInterface;

    public function hasField(
        string $name,
    ): bool;

    public function getFields(): FieldMap;

    /**
     * @param class-string<RelationInterface> $relationClass
     */
    public function relation(
        string $name,
        string $relationClass,
    ): RelationInterface;

    public function getRelation(
        string $name,
    ): ?RelationInterface;

    public function hasRelation(
        string $name,
    ): bool;

    public function getRelations(): RelationMap;

    public function metadata(
        string $key,
        mixed $value = null,
    ): mixed;

    public function end(): Registry;
}
```

This example is not permission to alter existing return semantics unnecessarily.

Use the actual Phase 4 methods and nullable behavior as the source of truth.

Requirements:

* `CollectionInterface` extends `DefinitionInterface`.
* `ViewDefinitionInterface` extends `DefinitionInterface`.
* Do not create another runtime metadata representation.
* Do not move Collection-only methods into the shared interface.
* Primary-key methods remain Collection-only.
* table/database/entity/source/mapper persistence metadata remain Collection-only unless independently meaningful for views.

---

# 6. Shared implementation

Avoid duplicating field, relation, metadata, Registry, and `end()` behavior between Collection and ViewDefinition.

Use one of these approaches:

* an abstract `AbstractDefinition`;
* focused reusable traits;
* another small internal shared implementation.

Preferred conceptual shape:

```php
abstract class AbstractDefinition extends DefinitionNode implements DefinitionInterface
{
    protected Registry $registry;

    public FieldMap $fields;

    public RelationMap $relations;

    public function getRegistry(): Registry;

    public function field(...): FieldInterface;

    public function getField(...): ?FieldInterface;

    public function hasField(...): bool;

    public function getFields(): FieldMap;

    public function relation(...): RelationInterface;

    public function getRelation(...): ?RelationInterface;

    public function hasRelation(...): bool;

    public function getRelations(): RelationMap;

    public function end(): Registry;
}
```

The exact class hierarchy may differ if current Collection inheritance makes another solution cleaner.

Requirements:

* do not duplicate authoritative data;
* do not introduce a second metadata array;
* do not move storage-specific Collection behavior into ViewDefinition;
* preserve current Collection APIs;
* preserve Registry master-array binding;
* keep the shared abstraction small.

---

# 7. Registry master-array format

Extend the Registry root format:

```php
[
    'collections' => [
        // Existing canonical Phase 4 collection definitions.
    ],

    'views' => [
        'user_summary' => [
            'class' => ViewDefinition::class,
            'name' => 'user_summary',
            'source' => 'users',
            'fields' => [],
            'relations' => [],
            'metadata' => [],
        ],
    ],
]
```

Requirements:

1. `collections` remains unchanged.
2. Add canonical root key `views`.
3. Missing `views` is normalized to an empty array during Registry construction.
4. Registry read-only access after normalization must not mutate the array.
5. View definitions contain only plain data.
6. Concrete view and nested wrapper classes are stored as class strings where needed.
7. Registry round-trip equality remains exact after normalization.

Do not store ViewDefinition objects in the array.

---

# 8. Definition name namespace

Collections and views share one logical definition-name namespace.

These are invalid together:

```text
collection: users
view: users
```

Reason:

* string-based view sources must resolve unambiguously;
* future queries will resolve definitions by name;
* relation and field paths must not depend on arbitrary precedence.

Add:

```php
DefinitionNameConflictException
```

Creating a view with an existing collection name must throw.

Creating a collection with an existing view name must throw.

Restoring an array containing both types with the same name must throw during Registry normalization.

This restriction applies only to root definition names.

Field and relation names remain scoped to their parent definition.

---

# 9. Registry view API

Add:

```php
public function view(
    string $name,
): ViewDefinitionInterface;

public function getView(
    string|ViewDefinitionInterface $view,
): ?ViewDefinitionInterface;

public function hasView(
    string $name,
): bool;

/**
 * @return array<string, ViewDefinitionInterface>
 */
public function getViews(): array;
```

Follow the existing Collection API conventions.

If `getCollection()` currently returns `null` for a missing name, `getView()` must do the same.

Do not independently change Collection missing behavior in this phase.

Add a common lookup:

```php
public function getDefinition(
    string|DefinitionInterface $definition,
): ?DefinitionInterface;

public function hasDefinition(
    string $name,
): bool;
```

Semantics:

* Collection or ViewDefinition may be passed directly.
* String names resolve in the shared namespace.
* Missing names return `null`, matching existing Registry optional lookup behavior.
* Passed definitions must belong to this Registry.
* Foreign Registry definitions are rejected through a focused exception.

Do not add a compiled Registry.

---

# 10. `Registry::view()`

`view($name)` must mirror the current observable behavior of `collection($name)` where applicable.

When creating a view:

1. Validate the name.
2. Reject conflict with a collection name.
3. Create its complete canonical default array.
4. Store:

   ```php
   'class' => ViewDefinition::class
   ```
5. Store:

   ```php
   'name' => $name
   ```
6. Initialize:

   ```php
   'source' => null
   'fields' => []
   'relations' => []
   'metadata' => []
   ```
7. Create and cache the ViewDefinition wrapper.
8. Preserve fluent chaining.

When called for an existing view, follow the same replacement/reuse behavior that the current Registry uses for existing collections.

Do not silently make View behavior merge while Collection behavior replaces, or vice versa.

Characterize and test the behavior.

---

# 11. ViewDefinition

Create:

```php
final class ViewDefinition extends AbstractDefinition implements ViewDefinitionInterface
```

or the equivalent hierarchy selected during implementation.

Required behavior:

* bound to Registry `views.<name>`;
* has stable wrapper identity;
* owns FieldMap and RelationMap wrappers;
* stores no duplicate state;
* supports custom ViewDefinition subclasses through class discriminators;
* supports `end()` returning Registry;
* supports metadata;
* supports Registry round trip.

Required methods:

```php
public function source(
    string|DefinitionInterface $source,
): self;

public function getSourceName(): ?string;

public function getSource(): ?DefinitionInterface;

public function hasSource(): bool;
```

Do not add final query/view semantics yet.

---

# 12. View source storage

Store only the source definition name:

```php
'source' => 'users'
```

Do not store:

* Collection object;
* ViewDefinition object;
* Registry object;
* query object;
* SQL;
* source type discriminator.

Because collection and view names share one namespace, the name is unambiguous.

## `source()`

Examples:

```php
$view->source('users');
```

```php
$view->source(
    $registry->getCollection('users')
);
```

```php
$view->source(
    $registry->getView('base_user_view')
);
```

Requirements:

* passed definitions must belong to the same Registry;
* source name may refer to a collection or another view;
* source may be declared before its target exists;
* setting source writes immediately to the master array;
* replacing source replaces the old source name;
* empty source names are rejected.

## `getSource()`

When no source is configured:

```php
$view->getSource(); // null
```

When configured:

* resolve through `Registry::getDefinition()`;
* return CollectionInterface or ViewDefinitionInterface;
* throw `DefinitionNotFoundException` when a configured name cannot be resolved.

Do not perform cycle detection in this phase.

Circular view-source validation belongs to the future query/view semantic phase.

---

# 13. ViewField

Create:

```php
class ViewField extends Field
```

or an equivalent subclass.

It must:

* be stored with:

  ```php
  'class' => ViewField::class
  ```
* bind to the ViewDefinition field array;
* preserve current Field metadata APIs;
* preserve display, interface, validation, description, type, alias, and metadata support;
* return ViewDefinition from `end()` at runtime;
* survive Registry round trip;
* support custom ViewField subclasses.

Do not add:

```text
from()
expression()
aggregate()
count()
sum()
one()
many()
explode()
resolver()
writeTo()
```

Those belong to a future semantic-view phase.

## Schema/storage methods

The existing Field class contains storage-oriented methods.

Do not redesign that entire hierarchy in this phase.

A ViewField may temporarily inherit those methods for API reuse.

Document that storage-specific ViewField methods have no execution semantics yet and will be reviewed when the query/view source model is designed.

Do not silently interpret a ViewField column as a real database column.

---

# 14. Generalize Field parent

Fields currently assume their parent is a Collection.

Change their parent contract to:

```php
DefinitionInterface
```

Required behavior:

```php
$field->getParent()
```

or the current equivalent returns either:

* CollectionInterface;
* ViewDefinitionInterface.

Update:

```php
FieldInterface::end()
```

to return:

```php
DefinitionInterface
```

At runtime:

* Collection fields return Collection;
* View fields return ViewDefinition.

Preserve static-analysis precision through PHPDoc generics if practical, but do not introduce a complex generic hierarchy merely for this.

## Collection-specific behavior

`Field::isPrimaryKey()` must remain valid:

```php
public function isPrimaryKey(): bool
{
    $parent = $this->getParent();

    return $parent instanceof CollectionInterface
        && $parent->hasPrimaryKey()
        && in_array(
            $this->getName(),
            $parent->getPrimaryKey(),
            true,
        );
}
```

For a ViewField:

```php
$field->isPrimaryKey(); // false
```

Do not introduce view identity in this phase.

If Field currently exposes a `getCollection()` method:

* preserve it only as a Collection-specific convenience if existing public code depends on it;
* make it throw a focused exception when called for a ViewField;
* add the new general accessor such as `getParent()` or `getDefinition()`;
* document the intentional behavior.

Do not falsely type a ViewDefinition as CollectionInterface.

---

# 15. FieldMap parent generalization

Change FieldMap parent typing from Collection-only to:

```php
DefinitionInterface
```

It must support both:

```text
Collection → standard Field
ViewDefinition → ViewField
```

When a ViewDefinition creates a field:

```php
$view->field('name', 'string');
```

default the concrete class to:

```php
ViewField::class
```

When a Collection creates a field, preserve:

```php
Field::class
```

Restoration uses the stored class discriminator.

Requirements:

* stable wrapper identity;
* runtime-only wrapper cache;
* write-through master array;
* insertion order;
* custom subclasses;
* cache invalidation;
* exact round trip;
* no duplicated field data.

---

# 16. Generalize Relation parent

Relations currently assume their parent is a Collection.

Change the parent contract to:

```php
DefinitionInterface
```

Update:

```php
RelationInterface::end()
```

to return:

```php
DefinitionInterface
```

At runtime:

* Collection relation returns Collection;
* View relation returns ViewDefinition.

Update RelationMap parent typing accordingly.

Do not change relation target behavior unnecessarily.

Existing storage relation classes may continue resolving target collections through:

```php
getCollection()
```

or equivalent APIs.

A relation parent being a ViewDefinition does not automatically make its target a view.

---

# 17. Relations on views

ViewDefinition must own a RelationMap and support the generic relation registration method:

```php
$view->relation(
    'posts',
    SomeRelationClass::class,
);
```

Do not automatically expose every Collection-specific convenience method on ViewDefinition unless its semantics are valid.

In particular, do not blindly copy:

```text
belongsTo()
hasOne()
hasMany()
manyToMany()
```

into the ViewDefinition interface only for symmetry.

If the shared implementation currently provides those methods, separate generic relation creation from Collection storage-relation conveniences.

For this phase:

* generic class-based relation registration is required;
* existing relation classes may be used when their constructor and metadata support a ViewDefinition parent;
* custom relation subclasses must survive round trip;
* no relation is loaded or executed.

Do not implement `ViewRelation` semantics yet unless a tiny neutral class is strictly required for structural tests.

---

# 18. Collection-specific relation safety

Some existing relation subclasses may:

* create fields on the parent;
* assume the parent has a table;
* inspect the parent primary key;
* infer storage columns;
* use Collection-only metadata.

Do not allow those methods to fail later through obscure type errors.

For each relation subclass:

1. Inspect whether it requires a Collection parent.
2. If it does, enforce that requirement explicitly.
3. Throw a focused exception when attached to a ViewDefinition.
4. Keep generic RelationMap capable of custom view-compatible relation classes.

Suggested exception:

```php
InvalidRelationParentException
```

Do not weaken Collection relation behavior.

Do not redesign relation semantics.

---

# 19. Metadata on views

ViewDefinition metadata uses the existing MetadataTrait/MetadataMap system.

Required:

```php
$view->metadata('label', 'User summary');
```

must update:

```php
$registry->all()['views']['user_summary']['metadata']['label']
```

Metadata must:

* remain plain data;
* survive round trip;
* retain current missing/overwrite behavior;
* use no separate metadata store.

---

# 20. Internalize definition binding

Phase 3 introduced:

```php
Collection::bindDefinitionArray(array &$items): void
```

This must not remain part of the public Collection API after this phase.

Remove it from:

* Collection public API;
* CollectionInterface, if present;
* public API documentation;
* application-facing examples.

Binding must be handled through internal definition construction infrastructure.

## Target architecture

The only component responsible for reconstructing wrappers should be:

```php
ON\Data\Definition\Internal\DefinitionFactory
```

or another clearly internal factory.

The factory must:

1. Validate stored class discriminators.
2. Instantiate the correct wrapper.
3. Bind the wrapper to the Registry-owned nested array.
4. Return the expected interface.
5. Avoid copying metadata.
6. Avoid requiring public per-class rebinding methods.

## DefinitionNode binding

Move common rebinding behavior into:

```php
ON\Data\Support\DefinitionNode
```

as a protected or otherwise internal mechanism.

Conceptual form:

```php
/**
 * @internal
 */
protected function bindDefinitionArray(
    array &$items,
): void;
```

The exact name may differ.

Requirements:

* subclasses do not expose their own public bind method;
* Collection no longer declares public `bindDefinitionArray()`;
* Field, Relation, ViewDefinition, Display, and Interface wrappers use the same internal mechanism;
* no binding method is advertised as application API.

---

# 21. Hydration constructor cleanup

Phase 3 added optional public array-reference constructor parameters.

Review every wrapper constructor:

* Collection;
* Field;
* Relation;
* Display;
* Interface;
* M2MThrough;
* ViewDefinition;
* ViewField.

Target result:

* normal public constructors contain only application-facing arguments;
* array-bound restoration is routed through internal factory APIs;
* internal hydration parameters are not part of the advertised public API.

Because PHP references and subclass construction may make complete removal impractical, use this priority:

1. Remove public hydration parameters entirely.
2. If impossible without unsafe reflection or major redesign, replace them with a clearly internal named constructor/factory.
3. If a technically public method remains necessary, mark it:

   ```php
   /** @internal */
   ```

   and exclude it from documented public API.
4. Do not leave both public constructor hydration and public `bindDefinitionArray()`.

Avoid reflection-heavy property mutation unless no cleaner option works.

Do not compromise master-array reference binding.

Document the exact solution in `phase-5-notes.md`.

---

# 22. DefinitionFactory

Expand the existing internal `DefinitionFactory` rather than creating several unrelated factories.

Conceptual responsibilities:

```php
DefinitionFactory::createCollection(...)
DefinitionFactory::createView(...)
DefinitionFactory::createField(...)
DefinitionFactory::createRelation(...)
DefinitionFactory::createDisplay(...)
DefinitionFactory::createInterface(...)
```

The exact methods may be generic internally.

The factory must validate that stored classes implement or extend the expected contract.

Examples:

* collection class implements CollectionInterface;
* view class implements ViewDefinitionInterface;
* field class implements FieldInterface;
* relation class implements RelationInterface.

Invalid class discriminators throw focused definition exceptions.

Do not let arbitrary cached class strings instantiate unrelated classes.

---

# 23. Registry wrapper caches

Add a runtime-only ViewDefinition wrapper cache:

```php
private array $views = [];
```

or equivalent.

Required identity:

```php
$registry->getView('user_summary')
    === $registry->getView('user_summary');
```

Collection cache behavior remains unchanged.

Field and Relation wrappers under views must also be stable.

When a view is replaced:

* invalidate only that view wrapper and its nested wrappers;
* preserve unrelated Collection and View wrappers.

Caches must not appear in `all()`.

---

# 24. Round-trip behavior

Build a Registry containing:

* simple collection;
* composite-PK collection;
* collection fields;
* collection relations;
* one view sourced from a collection;
* one view sourced from another view;
* view fields;
* a custom view-compatible relation;
* custom ViewDefinition subclass;
* custom ViewField subclass;
* metadata.

Then:

```php
$array = $registry->all();
$restored = new Registry($array);
```

Required:

```php
$restored->all() === $array;
```

After restoration verify:

* correct Collection subclass;
* correct ViewDefinition subclass;
* correct Field/ViewField subclasses;
* correct relation subclasses;
* source names;
* source resolution;
* metadata;
* `end()` behavior;
* wrapper identity.

Read-only access must not mutate the canonical array.

Mutation through restored wrappers must update it immediately.

---

# 25. Clone behavior

Extend Phase 3’s detached-clone behavior to:

* ViewDefinition;
* ViewField;
* view FieldMap;
* view RelationMap;
* nested wrappers.

A cloned view must not accidentally remain bound to the original Registry master array.

Document whether a cloned ViewDefinition:

* retains a Registry reference;
* becomes detached;
* can be registered later.

Preserve the established Collection clone philosophy.

Do not create hidden shared references.

---

# 26. Tests

Keep all Phase 1–4 tests.

Update only tests affected by intentional parent return-type or constructor-infrastructure changes.

Add the following tests.

## 26.1 Registry root tests

Cover:

* default `views` root;
* restoring old arrays without `views`;
* plain-data guarantee;
* exact round trip;
* collection/view name conflict.

## 26.2 Registry view API tests

Cover:

* create view;
* retrieve view;
* missing view behavior;
* `hasView()`;
* `getViews()`;
* stable wrapper identity;
* pass existing ViewDefinition;
* reject foreign Registry ViewDefinition;
* replacement/reuse behavior matching Collection.

## 26.3 Common definition tests

Run equivalent shared behavior for Collection and ViewDefinition:

* name;
* Registry;
* fields;
* relations;
* metadata;
* `end()`.

Use a data provider where appropriate.

## 26.4 Source tests

Cover:

* no source;
* source by collection name;
* source by CollectionInterface;
* source by view name;
* source by ViewDefinitionInterface;
* source declared before target;
* missing configured source;
* foreign Registry source;
* empty source;
* replacement;
* master-array write-through;
* round trip.

## 26.5 ViewField tests

Cover:

* default ViewField class;
* field type;
* alias;
* display;
* interface;
* validation;
* metadata;
* `isPrimaryKey() === false`;
* `end()` returns ViewDefinition;
* custom subclass;
* round trip.

## 26.6 Field parent tests

Cover:

* Collection field parent;
* View field parent;
* generic parent accessor;
* Collection-specific accessor behavior, if preserved;
* `end()` exact runtime parent;
* static-analysis-compatible contracts.

## 26.7 Relation parent tests

Cover:

* Collection relation parent;
* View relation parent;
* generic parent accessor;
* `end()` exact runtime parent;
* Collection-only relation rejecting View parent;
* custom view-compatible relation.

## 26.8 Internal binding tests

Verify:

* `Collection::bindDefinitionArray()` no longer exists publicly;
* public API inventory does not list it;
* restored wrappers still bind correctly;
* Registry registration still works;
* no second definition array is created;
* reverse visibility remains.

## 26.9 Constructor tests

Verify application-facing construction remains possible where it was previously supported.

Verify internal hydration arguments are absent from documented public signatures.

If an `@internal` named factory remains public for technical reasons, add an architecture test proving it is marked `@internal`.

## 26.10 Cache tests

Cover:

* stable View wrapper;
* stable ViewField wrapper;
* stable View Relation wrapper;
* replacement invalidation;
* unrelated wrapper preservation.

## 26.11 Clone tests

Cover detached View and ViewField clones.

## 26.12 Architecture tests

Production source must contain no public method declaration matching:

```text
Collection::bindDefinitionArray
```

The public API inventory must not expose raw array-reference hydration parameters as application APIs.

Verify no:

```text
ModelConfig
CollectionConfig
CompiledRegistry
```

was introduced.

---

# 27. Public API changes

Document these intentional changes:

```text
DefinitionInterface added

CollectionInterface extends DefinitionInterface

ViewDefinitionInterface added

ViewDefinition added

ViewField added

Registry::view() added

Registry::getView() added

Registry::hasView() added

Registry::getViews() added

Registry::getDefinition() added

Registry::hasDefinition() added

Field parent generalized to DefinitionInterface

FieldInterface::end() generalized to DefinitionInterface

Relation parent generalized to DefinitionInterface

RelationInterface::end() generalized to DefinitionInterface

Collection::bindDefinitionArray() removed from public API

Public hydration constructor parameters removed or marked internal
```

Record any actual additional changes.

Do not rename unrelated legacy APIs.

---

# 28. PHPStan policy

Phase 4 reports:

```text
configured level: 0
manual level 1: pass
```

At the end of Phase 5:

1. `composer analyse` must pass.
2. Manual PHPStan level 1 must pass.
3. Update `phpstan.neon.dist` from level 0 to level 1 if the complete Phase 5 code passes level 1.
4. Do not raise beyond level 1.
5. Do not add a baseline.
6. Do not add broad ignore rules.
7. Do not weaken parent types to `mixed` merely to pass analysis.

If the configured level cannot be raised to 1, document the exact new errors introduced and fix them within this phase unless they originate entirely from unchanged legacy code.

Given the Phase 4 result, level 1 is expected to become the configured baseline.

---

# 29. Documentation

Create or update:

```text
docs/phase-5-notes.md
docs/phase-5-view-format.md
docs/phase-3-storage-format.md
docs/phase-2-public-api.md
README.md
```

## `phase-5-notes.md`

Record:

1. Starting and ending commits.
2. Unrelated user changes excluded.
3. Shared definition implementation.
4. Registry view APIs.
5. View source behavior.
6. Field parent changes.
7. Relation parent changes.
8. Collection-only relation restrictions.
9. `bindDefinitionArray()` removal.
10. Hydration constructor solution.
11. DefinitionFactory changes.
12. Clone behavior.
13. Tests added and changed.
14. PHPStan configured and manual results.
15. Issues deferred to semantic views.
16. Issues deferred to querying.
17. Issues deferred to ORM.

## `phase-5-view-format.md`

Document:

* Registry `views` root;
* ViewDefinition keys;
* source storage;
* field storage;
* relation storage;
* metadata;
* class discriminators;
* reconstruction;
* round trip;
* current missing semantic features.

Do not document computed or aggregate field APIs as implemented.

---

# 30. Implementation order

Follow this order.

## Step 1 — Baseline

1. Isolate unrelated docs changes.
2. Commit Phase 4.
3. Run all quality commands.
4. Record baseline commit and test counts.

## Step 2 — Internal construction cleanup

1. Inventory all binding/hydration APIs.
2. Extend DefinitionFactory.
3. Move binding into DefinitionNode/internal infrastructure.
4. Remove public `Collection::bindDefinitionArray()`.
5. Remove or internalize hydration constructor parameters.
6. Run all existing tests before adding views.

Do not combine construction cleanup and ViewDefinition creation in one untested edit.

## Step 3 — Shared definition contract

1. Add DefinitionInterface.
2. Extract shared Collection behavior carefully.
3. Make CollectionInterface extend it.
4. Preserve Collection behavior.
5. Run all tests.

## Step 4 — Registry view storage

1. Add `views` root.
2. Add name-conflict validation.
3. Add Registry view caches.
4. Add Registry view methods.
5. Add common definition lookup.
6. Add tests.

## Step 5 — ViewDefinition

1. Add interface and class.
2. Add source storage/resolution.
3. Add metadata and `end()`.
4. Add custom subclass reconstruction.
5. Add tests.

## Step 6 — Fields

1. Generalize Field parent.
2. Generalize FieldMap parent.
3. Add ViewField.
4. Preserve Collection PK behavior.
5. Test ViewField round trip.

## Step 7 — Relations

1. Generalize relation parent.
2. Generalize RelationMap parent.
3. Identify Collection-only relation classes.
4. Add explicit parent validation.
5. Add custom view-compatible relation fixture.
6. Test round trip.

## Step 8 — Clone, cache, and round trip

1. Add clone tests.
2. Add wrapper identity tests.
3. Add invalidation tests.
4. Add complete Registry round-trip test.
5. Add no-read-mutation test.

## Step 9 — Quality and documentation

1. Regenerate public API inventory.
2. Update storage documentation.
3. Run configured PHPStan.
4. Run manual level 1.
5. Raise configured PHPStan to level 1.
6. Run tests and style.
7. Commit Phase 5.
8. Stop.

---

# 31. Definition of done

Phase 5 is complete only when:

1. Phase 4 is committed.
2. Unrelated user documentation changes are preserved.
3. Registry root contains canonical `collections` and `views`.
4. Collection and ViewDefinition implement DefinitionInterface.
5. ViewDefinition is stored entirely in the master array.
6. ViewDefinition supports fields.
7. ViewDefinition supports generic relations.
8. ViewDefinition supports metadata.
9. ViewDefinition supports source names.
10. Source resolves to Collection or ViewDefinition.
11. Collection and view names cannot conflict.
12. ViewField exists.
13. ViewField survives round trip.
14. ViewField `isPrimaryKey()` returns false.
15. Field parent supports Collection and ViewDefinition.
16. Relation parent supports Collection and ViewDefinition.
17. Collection-only relations reject invalid View parents explicitly.
18. Field and Relation `end()` return their actual parent.
19. View wrapper identity is stable.
20. ViewField wrapper identity is stable.
21. View Relation wrapper identity is stable.
22. Custom ViewDefinition subclasses survive restoration.
23. Custom ViewField subclasses survive restoration.
24. Complete Registry round trip is exact.
25. Read-only lookup does not mutate the array.
26. Wrapper mutation writes through immediately.
27. View clones do not retain hidden original-array references.
28. `Collection::bindDefinitionArray()` is no longer public.
29. Binding is handled by internal infrastructure.
30. Hydration constructor parameters are removed or clearly internalized.
31. No query semantics were added.
32. No aggregate APIs were added.
33. No database or persistence code was added.
34. No writable view behavior was added.
35. All Phase 1–4 tests pass after intentional contract updates.
36. All Phase 5 tests pass.
37. PHPStan level 1 passes.
38. PHPStan configured baseline is level 1.
39. Coding-style checks pass.
40. Dependency and architecture guards pass.
41. Phase 6 has not started.

---

# 32. Final response

At completion, report:

* Phase 4 ending commit;
* Phase 5 starting and ending commits;
* unrelated files preserved;
* files changed;
* final shared definition hierarchy;
* final Registry view API;
* final view storage format;
* source resolution behavior;
* Field parent changes;
* Relation parent changes;
* Collection-only relation restrictions;
* how `Collection::bindDefinitionArray()` was removed;
* how hydration constructors were internalized;
* DefinitionFactory changes;
* clone semantics;
* tests added and changed;
* PHPStan configured and manual results;
* all quality command results;
* deviations from this specification;
* concerns for semantic view fields;
* concerns for query design;
* concerns for future ORM integration.

Do not begin Phase 6.

Stop after Phase 5.
