# Implementation Task — Phase 3: Migrate Definitions to One Registry-Owned Master Array

## Objective

Refactor the mechanically extracted definition subsystem so that the complete definition is stored as one plain array owned by `Registry`.

Collection, Field, Relation, Display, Interface, Schema, and Metadata objects must become typed wrappers over portions of that array.

This phase changes the internal storage architecture only.

It must preserve the current public API and behavior characterized in Phase 2.

Do not implement the new primary-key design, `Key`, `ViewDefinition`, queries, mapping, persistence, or ORM behavior.

---

# 1. Required starting state

Before changing production code:

1. Inspect the current repository.
2. Read:

   * `docs/phase-1-notes.md`
   * `docs/phase-2-notes.md`
   * `docs/phase-2-source-manifest.md`
   * `docs/phase-2-public-api.md`
3. Run all existing quality commands.
4. Confirm all Phase 1 and Phase 2 tests pass.
5. Confirm the repository is a Git checkout.

The Phase 2 notes state that the previous workspace was not a Git checkout.

If this is still true:

1. Initialize Git in the target repository, or move the completed project into its real Git repository.
2. Add the current Phase 2 result.
3. Create a baseline commit before beginning Phase 3.
4. Record that baseline commit hash.

Do not start the master-array refactor without a baseline commit.

Create:

```text
docs/phase-3-notes.md
```

Record:

* starting commit hash;
* current PHPStan level;
* current test count;
* current production file count;
* current known Phase 2 deviations.

---

# 2. Scope

This phase must convert the complete definition object graph:

```text
Registry
├── Collection
│   ├── FieldMap
│   │   └── Field objects
│   │       ├── Display configuration
│   │       ├── Interface configuration
│   │       ├── Schema configuration
│   │       └── Metadata
│   └── RelationMap
│       └── Relation objects
│           ├── Display configuration
│           ├── Interface configuration
│           ├── relation-specific configuration
│           └── Metadata
```

into:

```text
Registry master array
├── collections
│   └── <collection name>
│       ├── scalar collection metadata
│       ├── fields
│       │   └── <field name>
│       │       └── plain field definition data
│       └── relations
│           └── <relation name>
│               └── plain relation definition data
```

Typed objects remain part of the public API, but they are runtime wrappers only.

The master array is the sole definition source of truth.

---

# 3. Explicit non-goals

Do not implement:

* collection-level `primaryKey()`;
* `ON\Data\Key`;
* removal of `PrimaryKeyDefinition`;
* removal of `PrimaryKeyValue`;
* removal of field-level primary-key flags;
* `DefinitionInterface`;
* generalized Field or Relation parents;
* `ViewDefinition`;
* Registry view APIs;
* query objects;
* QuerySpec;
* FieldRef;
* expressions;
* aggregates;
* relation handlers;
* database adapters;
* Mapper execution;
* FieldType execution;
* persistence;
* mutations;
* Unit of Work;
* REST or GraphQL integration;
* caching beyond reconstructing the Registry from its plain array;
* public API cleanup;
* legacy method renaming;
* PHPStan-wide type cleanup.

Do not start Phase 4 automatically.

---

# 4. Behavior preservation rule

The Phase 2 characterization tests define the behavior to preserve.

Unless this specification explicitly changes something, retain:

* method names;
* argument types;
* return types;
* defaults;
* exceptions;
* fluent chaining;
* object identities where currently observable;
* current Registry collection behavior;
* field-level primary keys;
* `PrimaryKeyDefinition`;
* `PrimaryKeyValue`;
* relation key behavior;
* relation subclasses;
* generated relation fields;
* display/interface/schema behavior;
* file-location tracking;
* collection clone behavior;
* map iteration behavior.

Do not clean up questionable legacy behavior during this phase.

In particular, preserve for now:

```text
Registry::getCollection() returning null when missing
Registry::collection() replacing/overwriting according to current behavior
RawDisplay::setOptions() current behavior
TagsInterface::getplaceholder()
FirstOfManyRelation cardinality behavior
```

Record these concerns, but do not fix them.

---

# 5. Master-array structure

`Registry` must own a complete plain array.

Conceptual structure:

```php
[
    'collections' => [
        'users' => [
            'class' => Collection::class,
            'name' => 'users',
            'table' => 'users',
            'database' => 'default',

            // Preserve all current collection metadata keys.
            'entity' => stdClass::class,
            'parentCollection' => null,
            'scope' => null,
            'repository' => null,
            'mapper' => null,
            'source' => null,
            'note' => null,
            'description' => null,
            'hidden' => false,
            'fileLocation' => null,
            'metadata' => [],

            'fields' => [
                'id' => [
                    'class' => Field::class,
                    'name' => 'id',

                    // Preserve all current field metadata.
                ],
            ],

            'relations' => [
                'posts' => [
                    'class' => HasManyRelation::class,
                    'name' => 'posts',

                    // Preserve all current relation metadata.
                ],
            ],
        ],
    ],
]
```

This is illustrative.

Use the existing Phase 2 property and metadata names as array keys whenever possible.

Do not broadly rename stored keys.

No `views` node is required yet.

The Registry may initialize with:

```php
[
    'collections' => [],
]
```

---

# 6. Plain-data requirement

The result of:

```php
$registry->all();
```

must recursively contain only:

* arrays;
* strings;
* integers;
* floats;
* booleans;
* null.

Class names and callable identifiers represented as strings are allowed.

The array must not contain:

* Registry objects;
* Collection objects;
* Field objects;
* Relation objects;
* FieldMap objects;
* RelationMap objects;
* MetadataMap objects;
* Display objects;
* Interface objects;
* DefinitionNode objects;
* closures;
* resources;
* Cycle objects;
* Doctrine objects.

Use the Phase 1 plain-data test helper.

Add a full Registry plain-data test.

---

# 7. Registry as root definition node

Use the actual Phase 1 support implementation as the foundation.

The Phase 1 support base is expected to be similar to:

```php
ON\Data\Support\DefinitionNode
```

Do not recreate another Config abstraction.

Adapt `Registry` so that it owns its root `$items` array using the existing support API.

Conceptually:

```php
final class Registry extends DefinitionNode
{
    public function __construct(array $items = [])
    {
        // Normalize root structure.
    }
}
```

Required behavior:

```php
$registry = new Registry();

$registry->all();
```

returns:

```php
[
    'collections' => [],
]
```

Construction from cached data:

```php
$restored = new Registry($registry->all());
```

must reconstruct fully functional typed wrappers.

The input array must not be unnecessarily copied after construction where the support abstraction already owns it.

---

# 8. Runtime wrapper caches

The master array is authoritative, but wrappers should have stable runtime identity.

`Registry` must cache Collection wrappers by collection name.

Required:

```php
$registry->getCollection('users')
    === $registry->getCollection('users');
```

`FieldMap` must cache Field wrappers by field name.

Required:

```php
$collection->getField('id')
    === $collection->getField('id');
```

`RelationMap` must cache Relation wrappers by relation name.

Required:

```php
$collection->getRelation('posts')
    === $collection->getRelation('posts');
```

Nested Display and Interface wrappers should also have stable identity when the existing API exposes repeated getters.

Wrapper caches:

* are runtime-only;
* must not appear in `all()`;
* must not become a second metadata source;
* must be invalidated when the corresponding definition is replaced;
* must not serialize.

Use separate private cache properties, not entries inside `$items`.

---

# 9. Array-bound definition nodes

Every definition wrapper must bind to its corresponding portion of the Registry array by reference.

Examples:

```php
$collectionItems = &$registryItems['collections']['users'];

$collection = new Collection(
    registry: $registry,
    items: $collectionItems,
);
```

```php
$fieldItems = &$collectionItems['fields']['name'];

$field = new Field(
    parent: $collection,
    items: $fieldItems,
);
```

```php
$relationItems = &$collectionItems['relations']['posts'];

$relation = new HasManyRelation(
    parent: $collection,
    items: $relationItems,
);
```

Required bidirectional behavior:

```php
$field->required(true);

assert(
    $registry->all()['collections']['users']['fields']['name']['required']
        === true
);
```

And:

```php
$registryItems = &$registry->getItemsReferenceForTestingOnly();

$registryItems['collections']['users']['fields']['name']['required'] = false;

assert($field->isRequired() === false);
```

Do not add a public method exposing the master array by reference merely for tests.

Use an internal test subclass, protected accessor, or reflection where necessary.

---

# 10. Collection migration

Convert Collection scalar properties into values stored in its bound array.

Examples include, where present:

* name;
* table;
* database;
* entity;
* parent collection;
* scope;
* repository;
* mapper;
* source;
* note;
* description;
* hidden;
* file location;
* metadata;
* fields;
* relations.

Remove independent scalar properties when the array contains the same information.

A property may remain only when it is runtime-only, such as:

* Registry reference;
* FieldMap wrapper;
* RelationMap wrapper;
* wrapper caches.

All setters must write to `$items`.

All getters must read from `$items`.

Example:

```php
public function table(string $table): self
{
    $this->set('table', $table);

    return $this;
}

public function getTable(): string
{
    return $this->get('table');
}
```

Preserve all existing defaults.

Defaults must be placed into the array when the collection is created, not maintained as disconnected property defaults.

The current standalone defaults remain:

```php
'mapper' => null
'source' => null
```

---

# 11. Registry collection behavior

Preserve the Phase 2 behavior of:

```php
$registry->collection($name);
```

including whether redefining a name replaces the existing collection.

Do not silently change it to merge definitions in this phase.

When a collection is created:

1. Create its complete default plain-data array.
2. Store the concrete collection class:

   ```php
   'class' => Collection::class
   ```
3. Apply the current table default.
4. Preserve current file-location tracking.
5. Create and cache the wrapper.
6. Bind FieldMap and RelationMap to its nested arrays.

When a collection is retrieved from cached data:

1. Read the stored class.
2. Validate that it implements `CollectionInterface`.
3. Instantiate the wrapper over the existing nested array.
4. Cache it.
5. Do not rewrite or reorder the stored metadata.

If older Phase 2 definitions do not contain a `class` key, default to:

```php
Collection::class
```

when importing them.

Store the normalized class key afterward only if doing so does not mutate `all()` unexpectedly during simple reads. Prefer normalizing in the constructor.

---

# 12. Collection registration

Preserve `Registry::register()` behavior.

It must no longer store the passed Collection object in the definition array.

Instead:

1. Export the collection’s plain definition data.
2. Store that data under the collection name.
3. Ensure the collection class is stored.
4. Create or bind the Registry-owned canonical wrapper.
5. Update the runtime wrapper cache.
6. Preserve the observable Phase 2 return behavior.

After registration:

```php
$registry->all()
```

must contain no object.

If importing arbitrary existing Collection objects proves incompatible with the new bound-node architecture, implement the narrowest plain-data export/import mechanism required by the current tests.

Do not retain the passed object as the authoritative instance if it is not bound to the Registry’s master array.

Document the canonical-instance behavior.

---

# 13. Field migration

Convert Field scalar and nested metadata into its bound array.

Preserve all current behavior, including:

* field-level primary key;
* name;
* alias;
* type;
* column;
* required;
* searchable;
* sensible;
* hidden behavior;
* defaults;
* cast-default;
* relation-generated metadata;
* validation;
* validation messages;
* description;
* typecast;
* schema;
* display;
* interface;
* metadata;
* filterable;
* auto increment;
* nullable;
* unique;
* index;
* comment;
* precision and scale;
* all other existing methods.

Store the concrete field class:

```php
'class' => Field::class
```

Custom Field subclasses must survive round-trip restoration.

All setters write through to the array.

All getters read from the array.

Field retains runtime references to:

* parent Collection;
* Registry indirectly through Collection;
* nested wrapper objects.

`Field::end()` must continue returning its Collection.

Do not generalize the parent type.

---

# 14. FieldMap migration

`FieldMap` becomes a typed facade over:

```php
$collectionItems['fields']
```

It must not own Field objects as authoritative storage.

Its production state may contain:

* parent Collection reference;
* reference or path to the fields array;
* runtime wrapper cache.

Required behavior:

```php
$fieldMap->has('name');
$fieldMap->get('name');
$fieldMap->set($field);
```

must operate against the nested array.

Iteration must lazily yield Field wrappers in array insertion order.

When creating a new field:

1. Initialize its plain-data defaults.
2. Store its class.
3. Bind a wrapper.
4. Cache the wrapper.

When retrieving from restored data:

1. Read its stored class.
2. Validate `FieldInterface`.
3. Instantiate over the nested array.
4. Cache it.

Missing stored class defaults to the standard Field class during import normalization.

Preserve column lookup and field-name lookup behavior.

When a field definition is replaced, invalidate only that field wrapper.

---

# 15. Relation migration

Convert AbstractRelation and every relation subclass to bound-array storage.

Preserve:

* relation name;
* parent Collection;
* target collection name;
* nullable;
* where;
* order;
* cascade;
* loader metadata;
* load behavior metadata;
* display;
* interface;
* metadata;
* cardinality;
* junction behavior;
* relation-specific fields;
* simple relation keys;
* composite relation keys;
* generated fields;
* M2M through metadata;
* every subclass-specific property.

Store the concrete relation class:

```php
'class' => HasManyRelation::class
```

Custom Relation subclasses must survive round-trip restoration.

`Relation::end()` must continue returning its Collection.

Do not generalize relation parents.

Do not add relation handlers.

Do not restore removed hard-coded loader defaults.

Nullable opaque loader metadata remains supported.

---

# 16. RelationMap migration

`RelationMap` becomes a typed facade over:

```php
$collectionItems['relations']
```

It follows the same architecture as FieldMap:

* nested array is authoritative;
* wrappers are lazy;
* concrete class comes from the stored class string;
* runtime cache is separate;
* iteration preserves insertion order;
* replacement invalidates the corresponding wrapper;
* missing class is normalized to the appropriate standard class only when unambiguous.

For relation definitions, missing class values may not always be inferable.

When importing older data without a class discriminator:

* use the existing relation object/class during `register()` imports;
* otherwise throw a focused definition exception rather than guessing the relation type.

---

# 17. Metadata migration

Convert `MetadataTrait` and `MetadataMap` to use nested plain arrays.

The authoritative structure should remain compatible with current metadata semantics.

Conceptually:

```php
[
    'metadata' => [
        'key' => 'value',
    ],
]
```

Do not store Metadata objects in the array.

Preserve:

* set;
* get;
* overwrite;
* missing behavior;
* iteration;
* Collection integration;
* Field integration;
* Relation integration.

If metadata values currently permit arbitrary objects or closures, document the actual behavior.

For this package’s cacheable definition requirement, production definitions must reject or fail plain-data validation when a non-plain value is present.

Do not silently serialize arbitrary objects.

---

# 18. Display migration

Convert every class under:

```text
src/Definition/Display/
```

to bound-array storage where it represents nested definition state.

Store the concrete display class when reconstruction requires it:

```php
[
    'class' => DatetimeDisplay::class,
    // Current display metadata.
]
```

Preserve current behavior, including known questionable behavior such as `RawDisplay::setOptions()`.

Do not fix those behaviors.

A restored Registry must recreate the correct display subclass.

Display wrappers must not appear in `Registry::all()`.

---

# 19. Interface-definition migration

Convert every class under:

```text
src/Definition/Interface/
```

to bound-array storage where it represents nested definition state.

Store the concrete class when needed:

```php
[
    'class' => TagsInterface::class,
    // Existing interface metadata.
]
```

Preserve:

* all existing methods;
* all current method names;
* all current defaults;
* inheritance;
* options;
* placeholders;
* relation-specific configuration;
* odd legacy names such as `getplaceholder()`.

Do not rename methods.

A restored Registry must recreate the correct interface-definition subclass.

---

# 20. Schema migration

Convert `SchemaTrait` and related schema state to bound-array storage.

Preserve field-level primary-key metadata in this phase.

The schema array must contain the same information currently represented by schema properties.

Do not move primary keys to Collection yet.

Do not remove primary-key values from fields.

Do not change the behavior where primary-key configuration affects other field flags.

That belongs to Phase 4.

---

# 21. Class discriminators

Use a consistent key:

```php
'class'
```

for runtime subclass reconstruction.

Store it for definitions whose concrete class affects behavior:

* Collection;
* Field;
* Relation;
* Display definition;
* Interface definition;
* other nested configurable subclasses.

Requirements:

* must be a valid class string;
* must implement or extend the expected contract;
* invalid values throw a focused exception;
* custom subclasses must work;
* the class value remains plain data;
* class validation occurs before instantiation.

Do not instantiate arbitrary unrelated classes merely because a cached array contains their names.

---

# 22. Construction and hydration contracts

Introduce small internal factories only where they reduce duplicated reconstruction logic.

Possible internal classes:

```text
DefinitionWrapperFactory
FieldFactory
RelationFactory
NestedDefinitionFactory
```

Do not create a second metadata hierarchy.

A factory may:

1. Validate a stored class string.
2. Bind the requested nested array.
3. Instantiate the wrapper.
4. Return the expected interface.

It must not copy the metadata or compile it into another representation.

Prefer clear constructors or named internal factories over reflection-heavy object mutation.

If existing constructors cannot bind array state cleanly, update them while preserving their public usage where characterized.

---

# 23. Registry round-trip

The complete definition must survive:

```php
$array = $registry->all();

$restored = new Registry($array);
```

Required equality:

```php
$restored->all() === $array;
```

A read-only lookup after restoration must not unexpectedly change the array.

After restoration, verify:

* Collection subclass;
* Collection values;
* Field subclass;
* Field values;
* Relation subclass;
* Relation values;
* Display subclass;
* Interface subclass;
* Metadata;
* schema metadata;
* simple relation keys;
* composite relation keys;
* M2M relation data;
* definition file locations;
* nullable mapper/source/loader values;
* fluent `end()` behavior.

Then mutate a restored wrapper and confirm the restored Registry array changes immediately.

---

# 24. Custom subclass round-trip

Add fixtures:

```text
tests/Fixture/CustomCollection.php
tests/Fixture/CustomField.php
tests/Fixture/CustomRelation.php
tests/Fixture/CustomDisplay.php
tests/Fixture/CustomInterface.php
```

Where the current architecture permits each subclass.

Build a Registry using these classes, serialize through `all()`, restore it, and assert the restored wrappers use the same classes.

Do not create artificial extension mechanisms that do not exist.

Use the current generic registration/configuration APIs.

---

# 25. Clone behavior

Review the Phase 2 clone characterization tests.

A clone must not accidentally remain bound to the original Registry array unless that is explicitly the characterized behavior.

For each cloneable class:

1. Determine the current behavior from tests/source.
2. Preserve that behavior where possible.
3. Add an explicit test proving whether it is:

   * a detached definition copy;
   * a wrapper over the same definition;
   * unsupported.
4. Document the result.

Do not allow PHP’s default clone behavior to create hidden shared references accidentally.

If clone semantics are ambiguous and untested, make the smallest safe behavior explicit and document it without changing public APIs unnecessarily.

---

# 26. Definition file tracking

Preserve current definition file-location tracking.

File locations must be stored as strings in collection definition data.

`Registry::getDefinitionFiles()` must continue returning current results.

Round-trip restoration must preserve the stored file paths.

Do not store stack trace objects or runtime frame arrays.

---

# 27. Tests

Keep all Phase 1 and Phase 2 tests.

Adapt tests only where internal object storage assumptions are intentionally replaced.

Do not weaken public behavior assertions.

Add the following new tests.

## 27.1 Registry plain-data test

Build a comprehensive Registry and recursively verify `all()` contains no objects, closures, or resources.

## 27.2 Collection write-through test

Every collection setter must immediately update the corresponding master-array value.

## 27.3 Field write-through test

Every representative field metadata category must update the master array:

* basic;
* schema;
* display;
* interface;
* validation;
* metadata.

## 27.4 Relation write-through test

Every representative relation metadata category must update the master array:

* basic;
* keys;
* ordering;
* loading;
* display;
* interface;
* metadata;
* relation-specific values.

## 27.5 Reverse visibility test

Mutating the underlying array through an internal test helper must be visible through existing wrappers.

Do not add a production public reference getter.

## 27.6 Wrapper identity test

Assert stable identity for repeated Collection, Field, Relation, Display, and Interface lookups.

## 27.7 Cache invalidation test

Replacing one field or relation must invalidate its wrapper while preserving unrelated cached wrappers.

## 27.8 Complete round-trip test

Include:

* multiple collections;
* all main field metadata;
* all relation subclasses;
* simple relation keys;
* composite relation keys;
* M2M;
* display subclasses;
* interface subclasses;
* metadata;
* definition files;
* custom subclasses.

## 27.9 Restored mutation test

Restore a Registry, modify nested wrappers, and assert `all()` changes.

## 27.10 No-read-mutation test

Restore from an array, perform getters only, and assert `all()` remains byte-for-byte/equality identical.

## 27.11 Old PK compatibility test

Confirm after migration:

* field-level PK declaration still works;
* `getPrimaryKeyFields()` retains current return behavior;
* `getPrimaryKey()` returns `PrimaryKeyDefinition`;
* `PrimaryKeyValue` still works;
* current URL behavior still works.

## 27.12 Phase 1 regression tests

Reference binding in `DefinitionNode` and Dot behavior must continue passing.

---

# 28. PHPStan policy

Do not raise PHPStan as part of this refactor.

Do not lower it below its current Phase 2 configuration.

Do not add a broad baseline or broad ignore patterns.

All existing configured analysis must pass.

Additionally, run PHPStan manually at the next level above the configured level and record:

* number of errors;
* major error categories;
* whether Phase 3 introduced new categories.

This additional run is informational and does not need to pass.

Record the results in:

```text
docs/phase-3-notes.md
```

Do not spend Phase 3 fixing unrelated legacy type issues.

---

# 29. Documentation

Create or update:

```text
docs/phase-3-notes.md
docs/phase-3-storage-format.md
docs/phase-2-public-api.md
```

## `phase-3-notes.md`

Record:

1. Starting commit hash.
2. Ending commit hash.
3. Files modified.
4. DefinitionNode integration approach.
5. Wrapper-cache architecture.
6. Constructor/factory changes.
7. Clone semantics.
8. Import normalization behavior.
9. API deviations, if any.
10. PHPStan configured-level result.
11. Next-level PHPStan result.
12. Any test changed and why.
13. Concerns deferred to Phase 4.
14. Concerns deferred to Phase 5.

## `phase-3-storage-format.md`

Document the actual master-array format:

* root keys;
* collection keys;
* field keys;
* relation keys;
* class discriminator behavior;
* nested display/interface/schema format;
* metadata format;
* defaults;
* restoration rules.

Document the implemented format, not merely this prompt’s example.

## Public API snapshot

Regenerate the public API inventory.

The Phase 2 and Phase 3 public APIs should be equivalent except for unavoidable constructor/internal infrastructure changes.

List all differences explicitly.

---

# 30. Implementation order

Use this order.

## Step 1 — Baseline

1. Ensure Git checkout.
2. Commit Phase 2.
3. Run all tests and quality checks.
4. Record baseline data.

## Step 2 — Design from actual classes

1. Inventory all independent definition properties.
2. Inventory all nested configurable objects.
3. Design the actual storage keys.
4. Write `phase-3-storage-format.md` draft.
5. Do not modify behavior yet.

## Step 3 — Registry and Collection

1. Make Registry own the master array.
2. Bind Collection wrappers.
3. Preserve Registry behavior.
4. Add Collection write-through tests.

## Step 4 — Field and FieldMap

1. Bind Field state.
2. Convert nested schema/display/interface/metadata state.
3. Convert FieldMap.
4. Add tests.

## Step 5 — Relations and RelationMap

1. Bind base relation state.
2. Convert each subclass.
3. Convert nested display/interface/metadata.
4. Convert RelationMap.
5. Test composite keys and M2M.

## Step 6 — Reconstruction

1. Add class discriminators.
2. Add safe wrapper factories.
3. Add constructor-from-array support.
4. Add round-trip tests.
5. Add custom subclass tests.

## Step 7 — Clone and replacement behavior

1. Define/test clone behavior.
2. Test wrapper invalidation.
3. Confirm no hidden shared references.

## Step 8 — Documentation and quality

1. Complete storage documentation.
2. Regenerate API inventory.
3. Run every quality command.
4. Run informational next-level PHPStan.
5. Commit Phase 3.
6. Stop.

---

# 31. Definition of done

Phase 3 is complete only when:

1. The target is a Git checkout.
2. Phase 2 has a baseline commit.
3. Registry owns the complete definition array.
4. Collection scalar metadata exists only in that array.
5. Field metadata exists only in that array.
6. Relation metadata exists only in that array.
7. Display metadata exists only in that array.
8. Interface metadata exists only in that array.
9. Schema metadata exists only in that array.
10. General metadata exists only in that array.
11. `Registry::all()` returns the complete definition.
12. `Registry::all()` contains no objects, closures, or resources.
13. Registry can be reconstructed from `all()`.
14. Round-trip preserves exact array equality.
15. Read-only lookup does not mutate restored data.
16. Collection wrappers are stable.
17. Field wrappers are stable.
18. Relation wrappers are stable.
19. Custom supported subclasses survive restoration.
20. Every setter writes through to the master array.
21. Reverse array changes are visible to wrappers in internal tests.
22. FieldMap and RelationMap are facades, not authoritative stores.
23. Wrapper caches remain runtime-only.
24. Existing fluent `end()` behavior remains.
25. Field-level primary keys remain.
26. `PrimaryKeyDefinition` remains.
27. `PrimaryKeyValue` remains.
28. Existing simple and composite relation behavior remains.
29. No ViewDefinition exists.
30. No `Key` class exists.
31. No query or persistence feature has been added.
32. All Phase 1 tests pass.
33. All Phase 2 tests pass.
34. New Phase 3 tests pass.
35. Static analysis passes at the configured level.
36. Coding-style checks pass.
37. Dependency guards pass.
38. Storage format is documented.
39. API differences are documented.
40. Phase 4 has not started.

---

# 32. Final response

At completion, report:

* starting and ending commit hashes;
* files modified;
* actual master-array structure;
* wrapper-binding approach;
* wrapper-cache approach;
* import/restoration strategy;
* clone behavior;
* API differences;
* tests added or changed;
* configured PHPStan result;
* next-level PHPStan result;
* all quality commands and results;
* unresolved concerns for primary-key Phase 4;
* unresolved concerns for ViewDefinition Phase 5.

Do not begin Phase 4.

Stop after Phase 3.
