# Final Definitions Simplification Phase

## Registry-Owned Nodes, Immutable Names, Direct Construction, and Basic Wrapper Caching

## Objective

Simplify the `ON\Data` definition architecture before beginning FieldType, Mapper, query, or persistence work.

The central definition array remains the source of truth.

This phase must establish these final rules:

1. Definition nodes cannot be created as independent orphan objects.
2. Every node is created by the owner of its position in the Registry tree.
3. Every custom stored definition class uses the same inherited constructor contract.
4. Node names are immutable and come from their owner map keys.
5. The final array slot is created before the wrapper object.
6. Wrappers bind directly to their final arrays during construction.
7. No construct-then-rebind lifecycle remains.
8. No public `register()` or attach/import-object API remains.
9. Wrapper objects are cached locally by their owners.
10. `DefinitionFactory` is stateless and does not manage caches.
11. The Registry remains close in size and responsibility to the old Overnight Registry.
12. Existing fluent collection, field, relation, display, interface, and view configuration remains available.

This is the final architecture pass for the definitions milestone.

---

# 1. Required baseline

Before modifying production code:

1. Inspect the complete repository.
2. Read all Phase 1–6 notes and the current public API documentation.
3. Preserve unrelated user-owned documentation changes.
4. Run:

   ```bash
   composer validate --strict
   composer test
   composer analyse
   composer check-style
   ```
5. Confirm PHPStan level 1 passes.
6. Commit the currently completed Phase 5 and Phase 6 work.

If the Phase 5 and Phase 6 changes cannot be accurately separated, create one combined baseline commit.

Record:

* baseline commit;
* test count;
* assertion count;
* PHPStan level;
* current public methods that will be intentionally removed.

Create:

```text
docs/final-definition-architecture.md
```

Do not start this phase from an uncommitted structural baseline.

---

# 2. Final ownership model

The ownership tree is:

```text
Registry
├── creates and owns Collection nodes
└── creates and owns ViewDefinition nodes

Collection / ViewDefinition
├── creates and owns Field nodes
└── creates and owns Relation nodes

Field / Relation
├── creates and owns Display nodes
└── creates and owns Interface nodes

M2MRelation
└── creates and owns M2MThrough
```

Every stored node belongs to exactly one Registry tree.

A node may not be created independently and attached later.

Examples of forbidden workflows:

```php
$collection = new Collection(...);
$registry->register($collection);
```

```php
$field = new Field(...);
$collection->getFields()->set($field);
```

```php
$relation = new CustomRelation(...);
$collection->getRelations()->replace($relation);
```

Required workflows:

```php
$collection = $registry->collection(
    'users',
    CustomCollection::class,
);
```

```php
$field = $collection->field(
    'email',
    'string',
    CustomField::class,
);
```

```php
$relation = $collection->relation(
    'posts',
    CustomRelation::class,
);
```

The owner:

1. creates the final array slot;
2. materializes the selected class defaults;
3. asks `DefinitionFactory` to construct a wrapper directly over that slot;
4. caches and returns the wrapper.

---

# 3. Explicit non-goals

Do not implement:

* a centralized wrapper identity map;
* a cache manager inside `DefinitionFactory`;
* path-based global wrapper identity;
* orphan-node registration;
* object import/export attachment;
* renaming nodes;
* copying nodes;
* cloning nodes;
* removing nodes;
* recursive Registry normalization;
* legacy definition migration;
* semantic view fields;
* expressions;
* aggregates;
* Query or QuerySpec;
* FieldType integration;
* Mapper integration;
* database adapters;
* persistence;
* mutations;
* Unit of Work;
* REST or GraphQL integration;
* PHP 8.4 property hooks.

Do not begin the next milestone automatically.

---

# 4. Registry is not a DefinitionNode

`Registry` is the root store and owner.

It should extend the generic Config support directly rather than behaving like an ordinary stored child node.

Conceptually:

```php
final class Registry extends Config
{
    /** @var array<string, CollectionInterface> */
    private array $collections = [];

    /** @var array<string, ViewDefinitionInterface> */
    private array $views = [];

    public function __construct(?array $items = null)
    {
        parent::__construct($items ?? [
            'collections' => [],
            'views' => [],
        ]);
    }
}
```

Registry responsibilities are limited to:

* root `collections` array;
* root `views` array;
* root name validation;
* collection/view shared-name conflicts;
* root wrapper creation;
* root wrapper lookup;
* root wrapper caches;
* definition file reporting;
* plain-data export validation.

Registry must not know:

* fields;
* relations;
* displays;
* interfaces;
* M2MThrough;
* primary-key migration;
* nested wrapper initialization;
* nested default materialization.

---

# 5. Names are contextual and immutable

## 5.1 Map keys are names

The name of every node is its key in its owner's array.

Canonical example:

```php
[
    'collections' => [
        'users' => [
            'class' => Collection::class,
            'table' => 'users',
            'fields' => [
                'email' => [
                    'class' => Field::class,
                    'type' => 'string',
                ],
            ],
            'relations' => [
                'posts' => [
                    'class' => HasManyRelation::class,
                ],
            ],
        ],
    ],
]
```

Do not store redundant node names:

```php
'name' => 'users'
'name' => 'email'
'name' => 'posts'
```

Remove `name` from:

* Collection arrays;
* ViewDefinition arrays;
* Field arrays;
* Relation arrays;
* nested stored node arrays where the owner key already identifies the node.

## 5.2 Runtime name

`DefinitionNode` receives its name during construction:

```php
$node->getName();
```

returns the contextual map key.

The name is runtime context, not duplicated definition data.

## 5.3 Remove name setters

Remove mutable methods such as:

```php
$collection->name(...)
$field->name(...)
$relation->name(...)
$view->name(...)
```

Retain:

```php
getName(): string
```

`getName()` should be implemented once by `DefinitionNode` and should be final where practical.

## 5.4 No renaming

Do not implement:

```php
renameCollection()
renameView()
renameField()
renameRelation()
```

in this phase.

Names are immutable after creation.

This prevents:

* root key/name synchronization;
* stale aliases;
* cache alias scans;
* detached wrappers after renaming;
* relation/path ambiguity.

---

# 6. DefinitionNode constructor contract

All stored custom definition classes must extend:

```php
ON\Data\Support\DefinitionNode
```

They must use one constructor contract.

Conceptual contract:

```php
abstract class DefinitionNode extends Config
{
    private readonly Registry|DefinitionNode $owner;

    private readonly string $name;

    final protected function __construct(
        Registry|DefinitionNode $owner,
        string $name,
        array &$items,
    ) {
        $this->owner = $owner;
        $this->name = $name;

        // Bind directly to the final array.
    }
}
```

The exact implementation should use the existing Config reference-binding support.

Requirements:

1. The constructor binds directly to the final Registry-owned array.
2. The constructor never creates a temporary definition array.
3. The constructor never rebinds later.
4. Custom subclasses cannot replace the constructor.
5. The constructor stores no duplicate definition data.
6. Custom behavior is implemented through methods after the node exists.
7. Custom defaults are supplied through `definitionDefaults()`.
8. Custom nested nodes are created lazily by custom methods.

Because the constructor must not be application-facing, use an internal static construction entry point.

Conceptual API:

```php
/**
 * @internal
 *
 * @return static
 */
final public static function fromDefinition(
    Registry|DefinitionNode $owner,
    string $name,
    array &$items,
): static {
    return new static($owner, $name, $items);
}
```

`DefinitionFactory` is the only production caller.

Mark the method `@internal`.

---

# 7. One-time runtime initialization

Remove:

```text
rebindDefinitionArray()
afterBindDefinitionArray()
DefinitionFactory::rebind()
```

No wrapper is constructed against the wrong array, so repair after rebinding is unnecessary.

If a class requires runtime-only child maps or caches, use one one-time initialization hook called after final binding:

```php
protected function initializeRuntimeState(): void
{
}
```

Rules:

1. It runs exactly once during final construction.
2. It must not alter definition data.
3. It may initialize runtime-only caches or map wrappers.
4. It must not apply defaults.
5. It must not normalize arrays.
6. Most custom nodes should not override it.
7. Child wrappers should preferably remain lazy.

This is not a rebinding hook.

---

# 8. Definition defaults

Each node class owns its own canonical defaults:

```php
protected static function definitionDefaults(): array
{
    return [
        // Node-specific data only.
    ];
}
```

`DefinitionNode::createDefinition()` must:

1. read `static::definitionDefaults()`;
2. merge explicit values;
3. set:

   ```php
   'class' => static::class
   ```
4. return a complete plain array;
5. not include the contextual node name.

Conceptual API:

```php
/**
 * @internal
 *
 * @param array<string, mixed> $values
 * @return array<string, mixed>
 */
final public static function createDefinition(
    array $values = [],
): array;
```

Merge rules:

* associative arrays merge recursively;
* list arrays replace;
* scalars replace;
* explicit null replaces a default;
* class discriminator is always the concrete static class.

Do not infer defaults from all arbitrary class properties.

Use explicit `definitionDefaults()` methods.

---

# 9. DefinitionFactory is stateless

`DefinitionFactory` must not contain:

* wrapper caches;
* Registry references stored in properties;
* node paths;
* identity-map behavior;
* normalization;
* array migration;
* rebind closures;
* export/import logic;
* M2M-specific logic.

It may contain generic static construction methods.

Required conceptual operations:

```php
DefinitionFactory::create(
    owner: $owner,
    name: $name,
    slot: $items[$name],
    class: $class,
    expectedType: FieldInterface::class,
    values: $values,
);
```

and:

```php
DefinitionFactory::restore(
    owner: $owner,
    name: $name,
    items: $items[$name],
    expectedType: FieldInterface::class,
);
```

## 9.1 `create()`

`create()` must:

1. validate the requested class;
2. verify it extends `DefinitionNode`;
3. verify it implements the expected interface;
4. create the final array with:

   ```php
   $class::createDefinition($values)
   ```
5. place it directly into the owner slot;
6. instantiate the wrapper directly over that final array;
7. return it.

## 9.2 `restore()`

`restore()` must:

1. read the stored class discriminator;
2. validate it;
3. instantiate directly over the existing array;
4. not modify the array;
5. return the wrapper.

## 9.3 Convenience methods

Typed convenience methods are allowed:

```text
collection()
view()
field()
relation()
display()
interface()
```

They must delegate to the same generic mechanism.

No convenience method may own a cache.

Remove:

```text
export()
rebind()
through()
materializeDefinitionArray()
```

---

# 10. Basic wrapper caches

Wrapper caching is required because collections and fields may be requested repeatedly during one request.

Caching must remain simple and owner-local.

## 10.1 Registry caches

Registry owns:

```php
/** @var array<string, CollectionInterface> */
private array $collections = [];

/** @var array<string, ViewDefinitionInterface> */
private array $views = [];
```

`getCollection()`:

1. returns cached wrapper when available;
2. otherwise restores from the stored array;
3. stores the wrapper;
4. returns it.

`getView()` behaves equivalently.

## 10.2 FieldMap cache

Each `FieldMap` owns:

```php
/** @var array<string, FieldInterface> */
private array $fields = [];
```

It caches fields belonging to that one parent definition.

## 10.3 RelationMap cache

Each `RelationMap` owns:

```php
/** @var array<string, RelationInterface> */
private array $relations = [];
```

## 10.4 Single-child caches

The owning wrapper may cache:

* Display;
* Interface;
* M2MThrough.

## 10.5 No centralized cache manager

Do not add a cache manager to `DefinitionFactory`.

Reasons encoded into the architecture:

* the factory creates objects but does not own node lifetime;
* owner maps already know child names and slots;
* names are immutable;
* no external attachment exists;
* no global path identity is required;
* no cross-Registry cache should exist;
* no worker-lifetime global state should exist.

## 10.6 Cache guarantees

Within one owner lifetime:

```php
$registry->getCollection('users')
    === $registry->getCollection('users');
```

```php
$collection->getField('email')
    === $collection->getField('email');
```

```php
$collection->getRelation('posts')
    === $collection->getRelation('posts');
```

This is local memoization, not a general object identity system.

---

# 11. Create-or-return semantics

External object replacement is removed.

To avoid stale cached wrappers, owner creation methods use create-or-return behavior.

## 11.1 Collections

Recommended signature:

```php
public function collection(
    string $name,
    ?string $class = null,
): CollectionInterface;
```

Behavior:

* validate name;
* reject conflict with a view;
* when missing:

  * use `$class ?? Collection::class`;
  * create canonical array;
  * create/cache wrapper;
* when existing:

  * return existing wrapper;
  * if an explicit `$class` is supplied and differs from the stored class, throw.

Do not overwrite an existing collection.

## 11.2 Views

Use the equivalent behavior.

## 11.3 Fields

Recommended signature:

```php
public function field(
    string $name,
    ?string $type = null,
    ?string $class = null,
): FieldInterface;
```

Behavior:

* when missing:

  * use supplied class or parent default field class;
  * create canonical array;
  * optionally set initial type through creation values;
  * cache and return;
* when existing:

  * return existing wrapper;
  * explicit conflicting class throws;
  * do not silently replace the field;
  * configuration continues through fluent methods.

## 11.4 Relations

```php
public function relation(
    string $name,
    string $class,
): RelationInterface;
```

Behavior:

* create when missing;
* return existing when the stored class matches;
* throw when a different class is requested;
* never replace an existing relation array.

## 11.5 Display, Interface, Through

Use equivalent semantics:

* create once;
* return the existing child thereafter;
* conflicting class requests throw;
* no silent replacement.

This preserves the no-orphan invariant.

---

# 12. Remove orphan-node APIs

Remove:

```php
Registry::register()
```

Remove object overloads:

```php
getCollection(string|CollectionInterface)
getView(string|ViewDefinitionInterface)
getDefinition(string|DefinitionInterface)
```

Replace with:

```php
getCollection(string $name)
getView(string $name)
getDefinition(string $name)
```

Remove:

```php
Registry::requireLocalDefinition()
```

Remove map APIs that attach existing objects:

```text
FieldMap::set(FieldInterface)
FieldMap::replace(FieldInterface)
RelationMap::set(RelationInterface)
RelationMap::replace(RelationInterface)
```

Maps should expose internal owner-driven creation/restoration, not public attachment.

If a map API is still needed internally, it should accept:

* name;
* class;
* initial values;

not an existing node object.

---

# 13. Shared definition namespace

Collections and views continue using one root logical namespace.

These cannot coexist:

```text
Collection users
View users
```

Keep one small conflict check.

Recommended private method:

```php
private function assertDefinitionNameAvailable(
    string $name,
): void;
```

Do not include type-specific branching beyond producing an appropriate error message.

A shared namespace keeps:

```php
getDefinition('users')
$view->source('users')
```

unambiguous.

---

# 14. Name validation

Keep one small non-mutating validation method.

Requirements:

* reject empty names;
* reject whitespace-only names;
* preserve valid names exactly;
* do not trim and store a modified name;
* do not require SQL-compatible identifiers;
* support logical names allowed by the current package unless Dot-path ambiguity requires explicit handling.

Because names are map keys, Registry does not need to validate a duplicated stored `name`.

Remove:

```text
requireDefinitionName() checks against nested name
root key/name reconciliation
cached alias reconciliation
```

---

# 15. Definition interfaces

Update interfaces to reflect immutable contextual names.

Keep:

```php
getName(): string;
```

Remove configuration methods:

```php
name(string $name)
```

from:

* CollectionInterface;
* ViewDefinitionInterface;
* FieldInterface;
* RelationInterface;
* other stored-node contracts where the owner map supplies the name.

Custom classes inherit `getName()` from `DefinitionNode`.

---

# 16. AbstractDefinition and child maps

`AbstractDefinition` owns its FieldMap and RelationMap.

It must not require rebinding.

It may initialize them once through `initializeRuntimeState()` or lazily.

Preferred runtime state:

```php
private ?FieldMap $fieldMap = null;
private ?RelationMap $relationMap = null;
```

Getters create them against the already-final arrays.

No child map may point at a temporary array.

No `afterBindDefinitionArray()` remains.

---

# 17. Display and Interface ownership

`DisplayTrait` and `InterfaceTrait` must:

1. create complete arrays through the selected node class defaults;
2. use owner-local single-child caches;
3. restore lazily from existing arrays;
4. throw on conflicting class requests;
5. never create an orphan object first;
6. never ask Registry to understand their structure.

---

# 18. M2MThrough ownership

`M2MRelation` exclusively owns `M2MThrough`.

Requirements:

* creation occurs in the final `through` array slot;
* restoration occurs lazily from that slot;
* wrapper is cached by `M2MRelation`;
* no Registry M2M knowledge;
* no DefinitionFactory-specific `through()` method;
* no rebinding repair hook.

---

# 19. Cloning is forbidden

A cloned definition node would be detached from its owner tree and would therefore be an orphan.

Remove detached-clone behavior.

Definition nodes should reject cloning.

Conceptual behavior:

```php
final public function __clone()
{
    throw new LogicException(
        'Definition nodes cannot be cloned.',
    );
}
```

Remove clone-specific array detachment code and tests.

If definition copying is needed in the future, implement it as an explicit owner operation that creates a new canonical node under a new name.

Do not add that feature now.

---

# 20. Canonical restoration

`Registry` construction from an array expects the current canonical format.

```php
$registry = new Registry($cached);
```

Registry validates only:

* root arrays;
* collection/view root-name conflicts;
* plain root structure where necessary.

Nested class validation is lazy:

* collection class when collection is accessed;
* field class when field is accessed;
* relation class when relation is accessed;
* display/interface class when accessed;
* through class when accessed.

No restoration operation may modify the stored array.

Old caches must be invalidated and regenerated.

No legacy migration remains.

---

# 21. Plain-data export

Keep:

```php
$registry->all();
```

with recursive plain-data validation.

It may validate:

* arrays;
* scalar values;
* null;
* no objects;
* no resources;
* no closures.

It must not:

* instantiate wrappers;
* fill defaults;
* rewrite definitions;
* migrate data;
* alter caches;
* mutate the Registry.

---

# 22. Custom DefinitionNode contract

Document the final extension contract.

A custom stored node must:

1. extend the appropriate provided DefinitionNode-based class, or directly extend DefinitionNode when implementing a supported definition interface;
2. inherit the final constructor;
3. not define its own constructor;
4. define `definitionDefaults()` when it has custom stored values;
5. expose custom fluent methods that directly modify `$items`;
6. create custom nested child definitions through owner methods;
7. store only plain data;
8. rely on the owner-local cache;
9. not assume it can be attached after construction.

Example:

```php
final class RemoteRelation extends AbstractRelation
{
    protected static function definitionDefaults(): array
    {
        return array_replace(
            parent::definitionDefaults(),
            [
                'endpoint' => null,
                'timeout' => 30,
            ],
        );
    }

    public function endpoint(string $endpoint): self
    {
        $this->set('endpoint', $endpoint);

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->set('timeout', $seconds);

        return $this;
    }
}
```

Creation:

```php
$collection
    ->relation('remote_items', RemoteRelation::class)
    ->endpoint('/items')
    ->timeout(10);
```

No custom constructor and no registration step.

---

# 23. Tests

Keep all still-valid tests and remove tests that require orphan nodes, registration, rebinding, or cloning.

Add tests for the following.

## 23.1 Registry simplicity

* `register()` does not exist;
* `requireLocalDefinition()` does not exist;
* getters accept strings only;
* no cached alias scan;
* no root stored names;
* same collection requested repeatedly returns same wrapper;
* same view requested repeatedly returns same wrapper.

## 23.2 Immutable names

* root key is returned by `getName()`;
* field key is returned by `getName()`;
* relation key is returned by `getName()`;
* no name setter exists;
* exported arrays contain no redundant node name;
* valid name is preserved exactly;
* empty and whitespace-only names fail.

## 23.3 Direct construction

* owner creates final array before wrapper;
* wrapper writes directly into final array;
* no rebind method exists;
* no after-bind method exists;
* no closure-based protected rebinding remains;
* custom node inherits constructor;
* custom constructor declarations are not required.

## 23.4 Caching

* repeated root lookup returns identical wrapper;
* repeated field lookup returns identical wrapper;
* repeated relation lookup returns identical wrapper;
* repeated display/interface lookup returns identical wrapper;
* repeated through lookup returns identical wrapper;
* caches contain wrappers only;
* caches do not appear in export;
* factory has no cache state.

## 23.5 Create-or-return

* repeated collection creation returns existing node;
* conflicting collection class throws;
* repeated view creation returns existing node;
* repeated field creation returns existing node;
* conflicting field class throws;
* repeated relation creation returns existing node;
* conflicting relation class throws;
* no stored array is replaced.

## 23.6 No orphan nodes

* direct constructor is inaccessible or marked internal;
* FieldMap cannot attach an existing field;
* RelationMap cannot attach an existing relation;
* Registry cannot register an existing collection;
* cloning throws;
* no export/import object attachment path exists.

## 23.7 Factory

* stateless;
* validates class;
* validates interface;
* validates DefinitionNode inheritance;
* creates canonical slot;
* restores without mutation;
* no `rebind()`;
* no `export()`;
* no `through()`;
* no cache manager.

## 23.8 Canonical round trip

* build complete Registry through fluent API;
* export;
* restore;
* export exact equality;
* repeated lookups use caches;
* mutations through restored wrappers update the central array;
* reads do not mutate.

## 23.9 Custom nodes

* custom Collection;
* custom ViewDefinition;
* custom Field;
* custom ViewField;
* custom Relation;
* custom Display;
* custom Interface;
* custom relation-owned nested node.

Each must:

* use inherited constructor;
* configure through methods;
* survive round trip;
* require no Registry or factory special case.

## 23.10 Existing behavior

Verify unchanged:

* collection primary keys;
* composite keys;
* `Key`;
* view source;
* field metadata;
* relation metadata;
* M2M metadata;
* display/interface metadata;
* fluent `end()` navigation.

---

# 24. Architecture guards

Add production-source guards proving:

```text
Registry::register does not exist
Registry::requireLocalDefinition does not exist
DefinitionFactory::rebind does not exist
DefinitionFactory::export does not exist
DefinitionFactory has no cache properties
DefinitionNode::afterBindDefinitionArray does not exist
DefinitionNode::rebindDefinitionArray does not exist
Definition nodes have no mutable name setter
No cached-alias foreach scan exists
No M2MThrough reference exists in Registry
No M2MThrough-specific factory method exists
No clone-detachment implementation exists
```

---

# 25. Documentation

Update:

```text
README.md
docs/definitions.md
docs/extending-definitions.md
docs/phase-3-storage-format.md
docs/phase-5-view-format.md
docs/release-0.1-checklist.md
```

Create:

```text
docs/final-definition-architecture.md
```

Document:

* no orphan nodes;
* owner-created nodes;
* immutable contextual names;
* names stored as map keys;
* class-string extension points;
* inherited constructor contract;
* direct binding to final arrays;
* owner-local wrapper caches;
* stateless DefinitionFactory;
* no cloning;
* no registration;
* canonical cache regeneration requirement.

Do not describe internal caches as an ORM-style identity map.

---

# 26. PHPStan and quality

Keep PHPStan level 1 as required.

Run:

```bash
composer validate --strict
composer install
composer dump-autoload
composer test
composer analyse
composer check-style
```

Run level 2 informationally.

Do not:

* lower PHPStan;
* add broad ignores;
* introduce mixed constructor contracts;
* weaken custom node interfaces.

---

# 27. Implementation order

## Step 1 — Baseline

1. Commit Phase 5/6.
2. Run quality commands.
3. inventory orphan-node and rebinding APIs.

## Step 2 — Names and lifecycle

1. make names contextual;
2. remove stored names;
3. remove name setters;
4. add final constructor contract;
5. remove clone support.

## Step 3 — DefinitionFactory

1. implement direct create/restore;
2. remove rebind/export;
3. remove cache considerations;
4. validate custom node contract.

## Step 4 — Registry

1. remove register;
2. remove object overloads;
3. remove ownership helper;
4. simplify creation/getters;
5. keep root caches;
6. implement create-or-return.

## Step 5 — Child owners

1. update AbstractDefinition;
2. update FieldMap;
3. update RelationMap;
4. update DisplayTrait;
5. update InterfaceTrait;
6. update M2MRelation.

## Step 6 — Cache tests

1. root wrappers;
2. field/relation wrappers;
3. single-child wrappers;
4. no cache export;
5. no factory cache.

## Step 7 — Extension tests

1. migrate custom fixtures;
2. enforce inherited constructor;
3. test custom methods and defaults;
4. test round trip.

## Step 8 — Documentation and verification

1. update storage format;
2. remove orphan/rebinding documentation;
3. run all checks;
4. commit;
5. stop.

---

# 28. Definition of done

This phase is complete only when:

1. No orphan-node registration exists.
2. Registry has no `register()`.
3. Registry getters accept names only.
4. No local-definition ownership helper remains.
5. Names are immutable.
6. Names come from owner map keys.
7. Stored arrays contain no redundant node names.
8. All stored custom nodes inherit one constructor contract.
9. Custom constructors are unnecessary and unsupported.
10. Final array slots exist before wrapper construction.
11. No wrapper rebinding exists.
12. No after-bind repair hook exists.
13. DefinitionFactory is stateless.
14. DefinitionFactory owns no cache.
15. Registry caches collections and views locally.
16. FieldMap caches fields locally.
17. RelationMap caches relations locally.
18. Display, Interface, and Through wrappers are cached locally.
19. Repeated lookups return identical wrappers.
20. No cache alias scans exist.
21. Existing nodes are not silently replaced.
22. Conflicting class requests throw.
23. FieldMap cannot attach external Field objects.
24. RelationMap cannot attach external Relation objects.
25. Definition nodes cannot be cloned.
26. Registry stays the only root owner.
27. Every nested node is created by its owner.
28. Custom nodes survive round trip without central special cases.
29. Export contains only plain data.
30. Reads do not mutate definition data.
31. Primary-key and Key behavior remains.
32. ViewDefinition behavior remains.
33. All tests pass.
34. PHPStan level 1 passes.
35. Style and dependency checks pass.
36. No query, mapping, adapter, persistence, or ORM work has begun.

---

# 29. Final report

At completion report:

* baseline and ending commits;
* public APIs removed;
* final Registry line count;
* final Registry responsibilities;
* final DefinitionNode constructor;
* final factory API;
* final cache locations;
* name storage changes;
* create-or-return behavior;
* custom node migration;
* clone removal;
* tests removed and added;
* test/assertion counts;
* PHPStan level 1 result;
* level 2 result;
* all quality-command results;
* documentation changes;
* deviations from this specification.

Stop after this phase.
