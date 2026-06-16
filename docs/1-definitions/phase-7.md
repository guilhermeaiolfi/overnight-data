# Final Definitions Architecture Pass

## Remove Central Normalization and Enforce Definition-Node Ownership

## Objective

Perform one final architectural cleanup of the `ON\Data` definition subsystem before beginning FieldType, Mapper, query, or persistence work.

This phase must:

1. Remove recursive definition normalization from `Registry`.
2. Make definition-node defaults the only mechanism for creating complete canonical definition arrays.
3. Treat arrays restored into `Registry` as already canonical.
4. Remove permanent legacy-definition migration behavior.
5. Restrict `Registry` to root-level concerns.
6. Restrict `DefinitionFactory` to class validation, instantiation, binding, and generic definition export.
7. Move nested-definition ownership to the node that owns the nested data.
8. Remove concrete `Field`, `AbstractRelation`, and `M2MThrough` knowledge from generic maps and factories.
9. Fix M2M-through restoration after wrapper rebinding.
10. Audit the definition subsystem for other cases where generic infrastructure knows concrete subtype details.
11. Preserve the current public definition DSL and master-array architecture.

Do not introduce a `DefinitionNormalizer`.

Do not introduce per-class normalization hooks.

---

# 1. Required starting state

Before changing production code:

1. Inspect the current repository and working tree.
2. Read all Phase 1–6 notes and storage documentation.
3. Preserve unrelated user-owned documentation changes.
4. Run:

   ```bash
   composer validate --strict
   composer test
   composer analyse
   composer check-style
   ```
5. Confirm the current 77 tests and 1166 assertions pass.
6. Confirm configured PHPStan level 1 passes.
7. Commit the completed Phase 5 and Phase 6 implementation.

If Phase 5 and Phase 6 cannot be separated reliably, create one honest combined commit rather than inventing inaccurate history.

Record the baseline commit in:

```text
docs/final-definitions-pass.md
```

Do not begin this phase from an uncommitted structural baseline.

---

# 2. Core architectural rule

The final rule is:

> Every definition node owns its defaults, directly reads and writes its bound array, creates its own nested definitions, and restores its own nested wrapper caches.

The Registry owns the tree, but it does not understand the internal shape of every branch.

The intended responsibilities are:

```text
Registry
├── root collections array
├── root views array
├── root names and conflicts
├── root wrapper caches
├── root definition lookup
├── plain-data export boundary
└── root definition creation

DefinitionNode
├── class-owned defaults
├── canonical definition-array creation
├── direct array get/set access
├── protected internal reference binding
└── clone detachment

DefinitionFactory
├── class-discriminator validation
├── expected-type validation
├── wrapper instantiation
├── internal array binding
└── generic definition export

Collection / ViewDefinition
├── fields
├── relations
├── metadata
└── their own scalar properties

Field
├── its scalar properties
├── display child
├── interface child
└── metadata

Relation
├── its common scalar properties
├── display child
├── interface child
└── metadata

M2MRelation
└── M2MThrough child
```

---

# 3. Explicit non-goals

Do not implement:

* `DefinitionNormalizer`;
* normalization hooks on every definition class;
* legacy cache migration;
* format migration services;
* semantic view fields;
* expressions;
* aggregates;
* Query;
* QuerySpec;
* FieldRef;
* RelationRef;
* relation handlers;
* FieldType integration;
* Mapper integration;
* database adapters;
* persistence;
* mutation plans;
* ValueRef;
* Unit of Work;
* entity mapping;
* REST integration;
* GraphQL integration;
* PHP 8.4 property hooks;
* query or entity caching.

Do not begin the next milestone automatically.

---

# 4. Meaning of “no normalization”

Remove all behavior that recursively rewrites stored definitions merely because a Registry was constructed.

Specifically, Registry construction must not:

* add nested field defaults;
* add nested relation defaults;
* add display defaults;
* add interface defaults;
* add M2M-through defaults;
* rewrite relation structures;
* migrate old field-level primary-key flags;
* trim and rewrite nested names;
* materialize defaults for every nested class;
* recursively validate every possible subtype.

Definitions created through the public fluent API must already be canonical.

Definitions restored through:

```php
new Registry($cachedDefinition);
```

are expected to have been produced by:

```php
$registry->all();
```

or by another compatible producer that follows the documented canonical format.

There is currently one consumer, and that consumer will be migrated with the package. Permanent backward-compatibility migration is not required.

---

# 5. DefinitionNode defaults

## 5.1 Class-owned defaults

Continue using:

```php
protected static function definitionDefaults(): array
```

as the source of defaults for each definition-node class.

Each stored node class must own all defaults for its own branch.

Examples:

```php
class Field extends DefinitionNode
{
    protected static function definitionDefaults(): array
    {
        return [
            'class' => static::class,
            'name' => '',
            'column' => null,
            'type' => null,
            'metadata' => [],
            // Existing field defaults.
        ];
    }
}
```

```php
abstract class AbstractRelation extends DefinitionNode
{
    protected static function definitionDefaults(): array
    {
        return [
            'class' => static::class,
            'name' => '',
            'collectionName' => '',
            'inner_keys' => [],
            'outer_keys' => [],
            'metadata' => [],
            // Existing relation defaults.
        ];
    }
}
```

Custom subclasses must be able to extend or override these defaults without Registry changes.

## 5.2 Canonical creation helper

Add one internal canonical-definition creation method to `DefinitionNode`.

Conceptual API:

```php
/**
 * Create a complete standalone definition array using this class's defaults.
 *
 * @internal
 *
 * @param array<string, mixed> $values
 * @return array<string, mixed>
 */
public static function createDefinition(
    array $values = [],
): array;
```

Requirements:

* call `static::definitionDefaults()`;
* merge explicit values over defaults;
* preserve late-static-binding class defaults;
* return plain array data;
* never bind the returned array;
* never inspect nested field/relation semantics;
* be marked `@internal`.

## 5.3 Merge behavior

The definition merge algorithm must distinguish maps from lists.

Rules:

* associative arrays may merge recursively;
* lists are replaced as complete values;
* scalar override replaces scalar default;
* null override replaces a non-null default;
* numeric list entries must not be appended accidentally.

Example:

```php
$defaults = [
    'primaryKey' => [],
    'metadata' => [
        'visible' => true,
    ],
];

$values = [
    'primaryKey' => ['tenant_id', 'id'],
    'metadata' => [
        'label' => 'Users',
    ],
];
```

Result:

```php
[
    'primaryKey' => ['tenant_id', 'id'],
    'metadata' => [
        'visible' => true,
        'label' => 'Users',
    ],
]
```

Do not use a merge algorithm that appends list values.

## 5.4 Constructor behavior

Constructing a new node may apply defaults:

```php
new Field($parent);
new HasManyRelation($parent);
new ViewDefinition($registry);
```

The node's initial private array must contain its complete class defaults.

## 5.5 Binding behavior

Binding or rebinding an existing Registry-owned array must not merge defaults into it.

Change the internal binding flow so that:

```php
DefinitionFactory::rebind($node, $items);
```

only:

1. points the wrapper at the supplied array;
2. invokes `afterBindDefinitionArray()`.

It must not rewrite `$items`.

Therefore remove default merging from:

```text
DefinitionNode::bind()
DefinitionNode::rebindDefinitionArray()
DefinitionNode::fromReference()
```

where applicable.

A restored array is either canonical or invalid. Reading it must not silently fix it.

---

# 6. Registry responsibilities

After this phase, `Registry` may know about:

* `Collection`;
* `CollectionInterface`;
* `ViewDefinition`;
* `ViewDefinitionInterface`;
* root collections and views;
* root definition names;
* collection/view name conflicts;
* runtime wrapper caches;
* local-definition ownership;
* definition files;
* plain-data export.

It must not import or mention:

```text
Field
FieldInterface
ViewField
RelationInterface
RawDisplay
DisplayInterface
InterfaceInterface
M2MThrough
PrimaryKeyDefinition
PrimaryKeyValue
```

unless a root-level type relationship genuinely requires one.

## 6.1 Remove recursive normalization methods

Delete:

```text
normalizeDefinitions()
normalizeCollectionDefinitions()
normalizeViewDefinitions()
normalizeFields()
normalizeRelations()
normalizeNestedDisplay()
normalizeNestedInterface()
normalizePlainArray()
normalizePrimaryKey()
```

Do not replace them with another recursive normalizer class.

## 6.2 Root construction only

Registry construction must:

1. apply only Registry's own root defaults;
2. require `collections` to be an array;
3. require `views` to be an array;
4. verify collection/view root-name conflicts;
5. validate root entry names without rewriting nested definitions.

Conceptual root defaults:

```php
protected static function definitionDefaults(): array
{
    return [
        'collections' => [],
        'views' => [],
    ];
}
```

## 6.3 Definition names

Replace name normalization with validation.

Do not silently trim and rewrite names during Registry restoration.

At minimum:

* name must be a string;
* whitespace-only name is invalid;
* stored root `name` must match its root array key;
* collection/view names must not conflict.

Preserve the exact valid name string.

Do not impose SQL identifier rules.

## 6.4 Root class handling

Canonical root definitions must contain a class discriminator.

Example:

```php
[
    'class' => Collection::class,
    'name' => 'users',
]
```

```php
[
    'class' => ViewDefinition::class,
    'name' => 'user_summary',
]
```

Registry may validate root collection and view class discriminators because those are root concepts.

It must not validate nested field, relation, display, interface, or M2M-through classes.

Those are validated when their owner/factory accesses them.

## 6.5 Root creation

Create root arrays through node-owned defaults:

```php
$this->items['collections'][$name] =
    Collection::createDefinition([
        'name' => $name,
        'table' => $name,
    ]);
```

```php
$this->items['views'][$name] =
    ViewDefinition::createDefinition([
        'name' => $name,
    ]);
```

`Collection::defaultDefinition()` and `ViewDefinition::defaultDefinition()` may remain as thin compatibility delegates, but they must not duplicate the full defaults.

Preferred:

```php
public static function defaultDefinition(string $name): array
{
    return static::createDefinition([
        'name' => $name,
        'table' => $name,
    ]);
}
```

For views:

```php
public static function defaultDefinition(string $name): array
{
    return static::createDefinition([
        'name' => $name,
    ]);
}
```

## 6.6 Remove fallback wrapper creation

Current root creation must not fall back to returning an unregistered object if factory restoration fails.

After writing a canonical root array:

* create the wrapper through `DefinitionFactory`;
* return it;
* allow focused factory exceptions to propagate.

Do not return:

```php
new Collection($this);
```

as a fallback for a failed registered definition.

---

# 7. Remove legacy primary-key migration

Delete permanent support for field-level:

```text
pk
primaryKey flag
```

migration.

Remove:

* scanning fields for legacy PK flags;
* conflict detection between legacy field PKs and collection PK metadata;
* documentation claiming automatic legacy PK normalization;
* tests for old-array PK migration.

The only canonical primary-key format is:

```php
'primaryKey' => ['id']
```

or:

```php
'primaryKey' => ['tenant_id', 'id']
```

The existing Overnight consumer must be updated to emit the canonical collection-owned format.

Old definition caches should be invalidated and regenerated.

Do not retain historic migration logic in Registry.

---

# 8. DefinitionFactory responsibilities

`DefinitionFactory` must become a generic wrapper factory.

It may:

1. read a stored class discriminator;
2. validate that the class exists;
3. validate that it implements or extends the expected type;
4. validate that it is a `DefinitionNode`;
5. instantiate it using its owner/parent;
6. bind it to the supplied canonical array;
7. return the wrapper.

It must not:

* materialize defaults during restoration;
* merge arrays;
* normalize stored definitions;
* understand M2M-through semantics;
* migrate legacy formats;
* know field/relation-specific nested structure.

## 8.1 Remove factory normalization/materialization

Delete:

```text
materializeDefinitionArray()
definitionDefaultsFor()
mergeArrays()
normalizeStoredClass()
```

Replace class handling with a non-mutating method such as:

```php
/**
 * @param class-string $expectedType
 * @return class-string
 */
private static function requireStoredClass(
    array $items,
    string $expectedType,
    string $context,
): string;
```

Requirements:

* `class` must already exist in the canonical array;
* validation must not write to `$items`;
* missing or invalid classes throw `InvalidDefinitionClassException`.

## 8.2 Generic node construction

Introduce a generic internal creation/binding path.

Conceptual API:

```php
/**
 * @template T of object
 *
 * @param class-string<T> $expectedType
 * @return T
 *
 * @internal
 */
public static function node(
    object $parent,
    array &$items,
    string $expectedType,
    string $context,
): object;
```

It may be implemented differently if PHPStan typing is cleaner through private generic helpers.

Existing convenience methods may delegate to it:

```text
collection()
view()
field()
relation()
display()
interface()
```

These categories are acceptable because they are generic definition-node roles.

## 8.3 Remove M2M-specific factory method

Delete:

```php
DefinitionFactory::through()
```

`DefinitionFactory` must not import or mention:

```php
M2MRelation
M2MThrough
```

M2MRelation must use the generic node factory.

---

# 9. DefinitionNode extension contract

Stored definition wrapper classes must:

1. extend `DefinitionNode`;
2. implement the expected public interface;
3. define canonical defaults;
4. accept their logical parent in their application-facing constructor;
5. support internal rebinding through the inherited protected infrastructure.

Make this requirement explicit in:

```text
docs/extending-definitions.md
```

Do not pretend that arbitrary implementations of `FieldInterface` or `RelationInterface` that do not use the array-backed DefinitionNode architecture can be restored from the Registry.

Fail clearly rather than silently losing their data.

---

# 10. FieldMap cleanup

Current FieldMap behavior must no longer depend on:

```php
$field instanceof Field
```

Replace concrete-class checks with the generic definition-node contract.

Required behavior for `set()` and `replace()`:

1. verify the field implements `FieldInterface`;
2. verify the field extends `DefinitionNode`;
3. export its complete plain definition array;
4. store that array;
5. recreate/rebind the canonical wrapper through `DefinitionFactory`;
6. never silently store `[]`.

If an incompatible Field implementation is passed, throw a focused exception.

Suggested exception:

```php
InvalidDefinitionClassException
```

or another existing focused definition exception.

Do not silently discard custom field metadata.

Custom Field subclasses extending `DefinitionNode` must survive Registry round trip.

---

# 11. RelationMap cleanup

Current RelationMap behavior must no longer depend on:

```php
$relation instanceof AbstractRelation
```

Replace it with the generic definition-node contract.

Required behavior for `set()` and `replace()`:

1. verify `RelationInterface`;
2. verify `DefinitionNode`;
3. export the full array;
4. store it;
5. recreate/rebind through `DefinitionFactory`;
6. never silently store `[]`.

Custom Relation subclasses must not need to extend `AbstractRelation` if they correctly:

* extend DefinitionNode;
* implement RelationInterface;
* provide the required constructor and defaults.

Do not lose custom relation metadata.

---

# 12. Registry registration cleanup

Remove:

```text
Registry::exportCollection()
```

Registry must not manually enumerate every Collection property.

`register()` must accept only a Collection that:

* implements `CollectionInterface`;
* extends `DefinitionNode`;
* contains plain definition data.

Required flow:

1. validate the collection's Registry ownership where appropriate;
2. obtain its complete array through the generic DefinitionNode API;
3. validate plain data;
4. validate the collection class discriminator;
5. store it under its name;
6. rebind or recreate the canonical Registry-owned wrapper;
7. update only the runtime collection cache.

If a non-array-backed Collection implementation is passed, throw explicitly.

Do not reconstruct a partial collection by calling every getter.

---

# 13. AbstractDefinition ownership

`AbstractDefinition` owns:

* fields;
* relations;
* metadata;
* its field map;
* its relation map;
* rebuilding those maps after rebind.

Registry must not initialize or normalize these children.

## 13.1 Creating fields

When creating a field:

1. instantiate the default/custom field class;
2. let its constructor apply its own defaults;
3. configure its name and optional type;
4. store its complete array through FieldMap;
5. return the canonical bound wrapper.

Do not initially store an incomplete array and rely on later normalization.

## 13.2 Creating relations

When creating a relation:

1. instantiate the requested relation class;
2. let its constructor apply defaults;
3. configure its name;
4. store its complete array through RelationMap;
5. return the canonical bound wrapper.

No Registry or factory normalization is involved.

---

# 14. Display ownership

Display data belongs to the object using `DisplayTrait`.

Update `DisplayTrait::display()` so it creates a complete display definition using the selected display class's defaults.

Do not create only:

```php
['class' => $type]
```

and rely on factory materialization.

Conceptual flow:

```php
$display = new $type($this);

$this->items['display'] = $display->all();

$displayItems = &$this->items['display'];

$this->display = DefinitionFactory::display(
    $this,
    $displayItems,
);
```

Equivalent use of `createDefinition()` is also valid.

Requirements:

* custom Display subclasses own their defaults;
* Registry does not know displays exist;
* factory does not materialize display defaults;
* restored display classes are validated lazily when accessed;
* wrapper caches are reset correctly after parent rebind.

---

# 15. Interface ownership

Apply the same rule to `InterfaceTrait`.

`interface()` must create a complete interface-definition array through the selected interface class.

Do not store only its class and rely on later normalization.

Requirements:

* custom interface classes own their defaults;
* Registry does not know interfaces exist;
* DefinitionFactory only instantiates and binds;
* wrapper caches reset correctly after parent rebind.

---

# 16. M2MThrough ownership and restoration

`M2MRelation` exclusively owns its `through` child.

Neither Registry nor DefinitionFactory may contain M2M-specific behavior.

## 16.1 Through creation

`M2MRelation::through()` must:

1. create a complete M2MThrough definition using M2MThrough defaults;
2. store it in:

   ```php
   $this->items['through']
   ```
3. bind the M2MThrough wrapper using the generic DefinitionFactory node path;
4. configure its collection;
5. return it.

## 16.2 Through restoration

Fix restoration after `M2MRelation` is rebound to a Registry-owned array.

The current constructor runs before factory rebinding, so constructor-only through hydration is insufficient.

Implement:

```php
protected function afterBindDefinitionArray(): void
```

in `M2MRelation`.

It must:

1. call the parent implementation;
2. clear any old through wrapper;
3. inspect the newly bound `through` array;
4. restore the M2MThrough wrapper when present;
5. use generic factory binding;
6. leave the wrapper absent when through is null.

Cloning must rebuild the through wrapper over the detached clone array.

Add a direct regression test proving a cached M2M relation restores a usable through wrapper.

---

# 17. Strict restoration behavior

Arrays restored into Registry must already be canonical.

Requirements:

* root collection/view arrays must have class discriminators;
* field arrays must have class discriminators;
* relation arrays must have class discriminators;
* configured display arrays must have class discriminators;
* configured interface arrays must have class discriminators;
* configured M2M-through arrays must have class discriminators.

Missing nested class discriminators should throw when the nested node is accessed.

Registry construction does not need to eagerly walk the whole tree.

Update tests that currently expect every nested invalid class to fail during Registry construction.

New expected behavior:

```text
Invalid root collection/view data
    → fails during Registry construction or root lookup

Invalid field data
    → fails when FieldMap hydrates that field

Invalid relation data
    → fails when RelationMap hydrates that relation

Invalid display/interface data
    → fails when its owner hydrates that child

Invalid through data
    → fails when M2MRelation hydrates through
```

No invalid data may be silently rewritten.

---

# 18. Read-only behavior

Preserve and strengthen the Phase 6 rule:

> Reading definitions must not mutate the master array.

Verify that these operations leave `Registry::all()` byte-for-byte/equality identical:

* `getCollection()`;
* `getView()`;
* `getField()`;
* `getRelation()`;
* `getDisplay()`;
* `getInterface()`;
* restoring M2MThrough;
* iteration over fields;
* iteration over relations;
* `getCollections()`;
* `getViews()`.

Only explicit configuration methods may mutate the definition array.

---

# 19. Plain-data boundary

Keep `Registry::all()` plain-data validation.

This is a root export boundary and is an appropriate Registry responsibility.

It must reject:

* objects;
* resources;
* closures;
* unsupported values.

It must not:

* normalize;
* fill defaults;
* instantiate wrappers;
* migrate formats;
* alter the definition.

Add a test proving that calling `all()` does not mutate the Registry data.

---

# 20. Architecture audit

Search the complete production source for generic infrastructure that knows concrete subtype internals.

At minimum, inspect:

```text
Registry
DefinitionFactory
DefinitionNode
AbstractDefinition
FieldMap
RelationMap
MetadataMap
DisplayTrait
InterfaceTrait
all relation maps/factories
all wrapper reconstruction code
```

Flag and correct cases such as:

* `instanceof Field` in generic field infrastructure;
* `instanceof AbstractRelation` in generic relation infrastructure;
* `M2MThrough` in Registry or generic factory code;
* concrete display classes in Registry;
* concrete interface classes in Registry;
* manual property enumeration of Collection;
* fallback storage of empty arrays;
* central switch/if chains for specific relation types;
* nested child initialization outside the owning class.

Do not remove concrete dependencies that are legitimate inside the owning class.

Examples of legitimate knowledge:

```text
M2MRelation knows M2MThrough
Field knows display/interface capabilities
Collection knows primary keys
ViewDefinition knows source
```

Examples of illegitimate knowledge:

```text
Registry knows M2MThrough
FieldMap requires concrete Field
RelationMap requires AbstractRelation
DefinitionFactory has M2M-specific public methods
```

Document findings and changes.

---

# 21. Extensibility proof

Add a test-only custom relation with custom nested data.

For example:

```text
CustomRelation
└── CustomRelationOptions
```

Requirements:

1. The custom relation class owns creation and restoration of its nested child.
2. Registry is not modified.
3. DefinitionFactory contains no custom-relation special case.
4. RelationMap stores and restores it.
5. The complete structure survives:

   ```php
   $array = $registry->all();
   $restored = new Registry($array);
   ```
6. The restored custom nested wrapper works.
7. Plain-data export remains valid.

This test proves that future relation types can introduce their own structures without changes to Registry.

---

# 22. Legacy and documentation cleanup

Remove documentation references to:

* automatic legacy field-PK normalization;
* recursive Registry normalization;
* eager nested materialization;
* Registry knowing nested definition shapes.

Update:

```text
README.md
docs/definitions.md
docs/extending-definitions.md
docs/phase-3-storage-format.md
docs/phase-4-key-format.md
docs/phase-5-view-format.md
docs/release-0.1-checklist.md
```

Document:

* canonical arrays are created by node defaults;
* restored arrays must already be canonical;
* old caches must be discarded and regenerated;
* each node owns its nested definitions;
* custom stored wrappers must extend DefinitionNode;
* DefinitionFactory validates and binds but does not normalize;
* read operations do not mutate definitions.

---

# 23. Tests

Keep all still-valid Phase 1–6 tests.

Remove or rewrite tests that specifically require recursive normalization or legacy migration.

Add tests for the following.

## 23.1 DefinitionNode defaults

* new node contains all defaults;
* explicit scalar override;
* associative nested override;
* list replacement rather than append;
* custom subclass defaults;
* class discriminator uses custom subclass;
* canonical definition contains only plain data.

## 23.2 Binding

* rebinding does not add defaults;
* rebinding does not alter canonical array;
* read-only hydration does not mutate;
* after-bind hooks rebuild nested caches.

## 23.3 Registry

* no recursive normalization methods;
* no nested-type imports;
* root arrays only;
* root name conflict;
* root class validation;
* exact name preservation;
* no fallback unregistered wrapper;
* plain-data export unchanged.

## 23.4 Factory

* validates class;
* validates expected interface;
* validates DefinitionNode inheritance;
* binds without rewriting;
* contains no M2M-specific method;
* contains no default materialization method.

## 23.5 Maps

* custom Field subclass is stored completely;
* incompatible Field implementation throws;
* custom Relation subclass not extending AbstractRelation is stored completely when it extends DefinitionNode;
* incompatible Relation implementation throws;
* no empty-array fallback;
* replacement invalidates only the replaced wrapper.

## 23.6 Display and Interface

* creator materializes class defaults;
* custom subclass defaults survive;
* round trip;
* rebind cache correctness;
* no Registry involvement.

## 23.7 M2M

* through creation stores complete defaults;
* through restoration after factory rebind;
* through clone detachment;
* no Registry M2M knowledge;
* no DefinitionFactory `through()` method.

## 23.8 Strict restoration

* valid canonical Registry restores;
* incomplete field fails on access;
* missing relation class fails on access;
* invalid display class fails on access;
* invalid interface class fails on access;
* invalid through class fails on access;
* no array is rewritten.

## 23.9 Legacy removal

* production code contains no field-PK migration;
* Registry contains no `normalizePrimaryKey`;
* README no longer advertises legacy migration;
* canonical collection primary keys still work.

## 23.10 Extensibility

* custom relation with custom nested child round-trips without Registry/factory special cases.

---

# 24. PHPStan and quality policy

Keep configured PHPStan level 1.

Required commands:

```bash
composer validate --strict
composer install
composer dump-autoload
composer test
composer analyse
composer check-style
```

Run:

```bash
vendor/bin/phpstan analyse \
    --configuration phpstan.neon.dist \
    --level=2
```

informationally.

Do not:

* lower PHPStan;
* add a broad baseline;
* add broad ignore rules;
* weaken interfaces to `mixed` to avoid correct typing.

Update the level-2 error count after this refactor.

---

# 25. Implementation order

## Step 1 — Baseline

1. Commit Phase 5 and 6.
2. Run all quality commands.
3. Record test and PHPStan counts.

## Step 2 — DefinitionNode creation/binding

1. Add canonical definition creation.
2. fix list-versus-map merge behavior;
3. stop merging defaults during rebind;
4. add tests.

## Step 3 — Simplify Registry

1. remove recursive normalization;
2. remove legacy PK migration;
3. retain root validation only;
4. create root arrays through node defaults;
5. remove manual Collection export;
6. add tests.

## Step 4 — Simplify DefinitionFactory

1. remove materialization;
2. remove normalizing/mutating class resolution;
3. add strict class resolution;
4. add generic node binding;
5. remove M2M-specific method;
6. add tests.

## Step 5 — Fix maps

1. remove concrete Field dependency;
2. remove AbstractRelation dependency;
3. reject incompatible nodes;
4. preserve complete custom metadata;
5. add tests.

## Step 6 — Move child ownership

1. update DisplayTrait;
2. update InterfaceTrait;
3. update M2MRelation;
4. implement after-bind through restoration;
5. add tests.

## Step 7 — Architecture audit

1. search all generic infrastructure;
2. remove remaining subtype-specific leaks;
3. add custom relation nested-child proof;
4. document findings.

## Step 8 — Documentation and verification

1. update docs;
2. run all quality commands;
3. run level-2 PHPStan;
4. commit the final definitions pass;
5. stop.

---

# 26. Definition of done

This final pass is complete only when:

1. Phase 5 and 6 have a committed baseline.
2. Registry contains no recursive nested normalization.
3. Registry contains no field-specific logic.
4. Registry contains no relation-specific logic.
5. Registry contains no display/interface logic.
6. Registry contains no M2MThrough logic.
7. Registry contains no legacy primary-key migration.
8. DefinitionNode owns canonical default creation.
9. Node creation materializes defaults.
10. Node rebinding does not materialize defaults.
11. Lists replace rather than append during default creation.
12. Canonical restoration does not mutate arrays.
13. DefinitionFactory does not normalize definitions.
14. DefinitionFactory does not materialize defaults.
15. DefinitionFactory contains no M2M-specific method.
16. Factory validates DefinitionNode-based extension classes.
17. FieldMap has no concrete Field requirement.
18. RelationMap has no AbstractRelation requirement.
19. Maps never silently store empty arrays.
20. Registry no longer manually enumerates Collection properties.
21. Display owners create complete Display arrays.
22. Interface owners create complete Interface arrays.
23. M2MRelation exclusively owns M2MThrough.
24. M2MThrough restores correctly after rebind.
25. Custom relation nested data round-trips without central changes.
26. `Registry::all()` remains plain-data validated.
27. Read operations do not mutate definitions.
28. Old caches are documented as requiring regeneration.
29. Current Collection primary-key behavior remains correct.
30. Current ViewDefinition behavior remains correct.
31. All valid Phase 1–6 tests pass.
32. New architecture tests pass.
33. PHPStan level 1 passes.
34. Style checks pass.
35. Dependency guards pass.
36. No query, mapping, adapter, persistence, or ORM work was started.

---

# 27. Final report

At completion, report:

* baseline and ending commits;
* files changed;
* Registry methods removed;
* Registry imports removed;
* DefinitionNode creation and binding behavior;
* default merge behavior;
* DefinitionFactory methods removed and added;
* map concrete dependencies removed;
* Registry export changes;
* Display/Interface ownership changes;
* M2MThrough restoration fix;
* architecture leaks found elsewhere;
* custom relation extensibility test;
* tests removed, changed, and added;
* PHPUnit count and assertions;
* configured PHPStan result;
* level-2 PHPStan result;
* all quality-command results;
* documentation updated;
* deviations from this specification.

Do not begin FieldType, Mapper, query, or persistence work.

Stop after this final definitions architecture pass.
