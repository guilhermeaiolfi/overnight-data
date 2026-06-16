# Implementation Task — Phase 2: Mechanically Extract the Definition System

## Objective

Migrate the complete existing Overnight definition subsystem into the standalone `overnight-data` repository.

This phase is a **mechanical extraction and characterization phase**.

The goals are:

1. Copy the current definition subsystem from Overnight.
2. Change its namespace from `ON\ORM\Definition` to `ON\Data\Definition`.
3. Make the extracted code compile and run independently.
4. Preserve the current behavior and public API as closely as possible.
5. Add characterization tests that document that behavior.
6. Identify all dependencies and questionable behaviors before the master-array refactor.

Do **not** implement the master-array Registry in this phase.

Do **not** implement the new collection-level primary-key API, `Key`, or `ViewDefinition` in this phase.

---

# 1. Prerequisites

Phase 1 must already be complete.

The target repository must already contain:

```text
composer.json
src/Support/Dot.php
src/Support/DefinitionNode.php
tests/
docs/
```

or the equivalent files actually produced during Phase 1.

Before making changes:

1. Inspect the current target repository.
2. Read `docs/phase-1-notes.md`.
3. Run all existing Phase 1 quality commands.
4. Confirm that the Phase 1 tests pass.
5. Record the current target commit hash.
6. Record the exact source commit hash from the Overnight `cycle-mutation` branch.

Do not assume the Phase 1 implementation used precisely the class names described in the original prompt.

Use the actual completed Phase 1 implementation as the target foundation.

Do not rename or redesign Phase 1 support classes during this phase unless a concrete extraction blocker requires it.

---

# 2. Source repository

Use this repository and branch as the authoritative source:

```text
https://github.com/guilhermeaiolfi/overnight/tree/cycle-mutation
```

Source directory:

```text
src/ORM/Definition/
```

Do not copy from another branch.

Do not use an older local copy without comparing it to the current branch.

Record the source commit hash in:

```text
docs/phase-2-notes.md
```

If both repositories are locally available, use the local Overnight checkout after confirming that it is on the correct branch and commit.

Do not modify the original Overnight repository.

---

# 3. Scope

Copy the complete contents of:

```text
src/ORM/Definition/
```

including all nested directories and files.

Expected structure includes:

```text
Definition/
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

The extraction must include every production PHP file found recursively under the source directory.

Do not manually select only the classes that appear important.

Before copying, generate a source manifest containing:

* relative file path;
* declared namespace;
* declared classes, interfaces, traits, and enums;
* external namespace dependencies;
* whether an equivalent test exists in Overnight.

Save it as:

```text
docs/phase-2-source-manifest.md
```

The manifest must be based on the actual source tree, not only the expected list in this specification.

---

# 4. Target location and namespace

Copy the source tree into:

```text
src/Definition/
```

Perform this namespace migration:

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
ON\ORM\Definition\Field\Field
    ↓
ON\Data\Definition\Field\Field
```

```text
ON\ORM\Definition\Relation\HasManyRelation
    ↓
ON\Data\Definition\Relation\HasManyRelation
```

Update all internal imports and fully qualified references accordingly.

After migration, production files must not refer to:

```text
ON\ORM\Definition\
```

Do not add compatibility aliases for the old namespace.

---

# 5. Mechanical extraction rule

The first pass must be mechanical.

Use this sequence:

1. Copy every source file.
2. Change namespaces and internal imports.
3. Run syntax checks.
4. Inventory unresolved dependencies.
5. Make only the minimum changes needed to compile independently.
6. Port or add characterization tests.
7. Stop.

Do not combine extraction with architectural refactoring.

In particular, preserve the current implementation model:

```text
Registry
    owns Collection objects

Collection
    owns normal PHP properties
    owns FieldMap
    owns RelationMap

FieldMap
    owns Field objects

RelationMap
    owns Relation objects

MetadataTrait
    owns MetadataMap
```

Do not convert these objects to the Phase 1 array-backed `DefinitionNode` yet.

That conversion belongs to Phase 3.

---

# 6. Behaviors that must remain unchanged in Phase 2

Preserve the current behavior of:

* `Registry::collection()`;
* `Registry::register()`;
* `Registry::getCollection()`;
* `Registry::getDefinitionFiles()`;
* collection table-name defaults;
* definition file-location discovery;
* collection setters and getters;
* collection `end()` returning the Registry;
* field creation and reuse;
* relation creation;
* relation convenience methods;
* field `end()` returning the Collection;
* relation `end()` returning the Collection;
* `FieldMap`;
* `RelationMap`;
* display definitions;
* interface definitions;
* metadata definitions;
* schema metadata;
* generated relation fields;
* simple relation keys;
* composite relation keys already present in the branch;
* current exceptions;
* current clone behavior;
* current iteration behavior;
* current defaults, except for the two adapter-specific defaults described later.

Do not “improve” behavior merely because it seems awkward.

Characterize it with tests and defer redesign.

---

# 7. Primary-key behavior must remain old for now

Do not implement the new primary-key design in Phase 2.

Keep:

```php
$field->primaryKey(true);
```

Keep:

```php
$field->isPrimaryKey();
```

Keep:

```php
$collection->getPrimaryKeyFields();
```

with its current return behavior.

Keep:

```php
$collection->getPrimaryKey();
```

returning:

```php
PrimaryKeyDefinition
```

Keep both:

```text
PrimaryKeyDefinition
PrimaryKeyValue
```

under the migrated namespace:

```text
ON\Data\Definition\Collection
```

Do not create:

```php
ON\Data\Key
```

Do not add:

```php
$collection->primaryKey(...)
```

Do not change field-level PK metadata.

Do not change incomplete-key behavior.

Do not remove URL ID behavior.

Those changes belong to Phase 4.

The purpose of retaining them now is to establish tests showing exactly what will be replaced later.

---

# 8. Collection and relation parent types must remain old for now

Do not add the future common `DefinitionInterface`.

Keep Field parents typed as:

```php
CollectionInterface
```

Keep Relation parents typed as:

```php
CollectionInterface
```

Keep:

```php
FieldInterface::end(): CollectionInterface
```

Keep:

```php
RelationInterface::end(): CollectionInterface
```

Do not generalize these to support views yet.

That change belongs to Phase 5.

---

# 9. Do not implement views

Do not create:

```text
ViewDefinition
ViewDefinitionInterface
ViewField
```

Do not add Registry view methods.

Do not add:

```php
$registry->view(...)
```

Do not add a `views` array.

Views belong to Phase 5, after the master-array and primary-key migrations.

---

# 10. External dependency inventory

Before changing external references, inspect every migrated production file and create a dependency table in:

```text
docs/phase-2-notes.md
```

For every dependency outside:

```text
PHP built-ins
ON\Data\Definition
```

record:

* source file;
* imported symbol;
* whether it is used at runtime;
* whether it appears only in PHPDoc;
* why it exists;
* how it was handled in the standalone package.

Classify each dependency as one of:

```text
A. Internal definition dependency — migrate and rename
B. PHP built-in — keep
C. Adapter-specific default — remove or neutralize
D. Definition-local missing contract — migrate only if truly part of definitions
E. Unrelated Overnight runtime dependency — remove
F. Documentation-only reference — replace with generic class-string annotation
```

Do not add the entire Overnight package to satisfy dependencies.

Do not add Cycle or Doctrine.

---

# 11. Required adapter-specific changes

The current Collection implementation has adapter/framework-specific defaults for:

```php
Cycle\ORM\Mapper\StdMapper
ON\ORM\Select\Source
```

The standalone package must not depend on either class.

Remove those imports.

Change the stored defaults to:

```php
null
```

The resulting properties must be nullable:

```php
protected ?string $mapper = null;
protected ?string $source = null;
```

Update the public API only as much as required:

```php
public function mapper(?string $mapper): self;

public function getMapper(): ?string;

public function source(?string $source): self;

public function getSource(): ?string;
```

If the actual current interface already allows a nullable getter, preserve it.

This is the only planned public API deviation in Phase 2.

Add tests for:

```php
$collection->getMapper() === null;
$collection->getSource() === null;
```

and:

```php
$collection
    ->mapper(CustomMapper::class)
    ->source(CustomSource::class);
```

The methods continue to store opaque class strings.

Do not create placeholder classes for Cycle’s mapper or Overnight’s query source.

Do not use string literals containing their old fully qualified names as hidden defaults.

Document this deviation clearly.

---

# 12. Other external symbols

If another migrated class references an external Overnight or Cycle symbol:

## 12.1 Runtime dependency

Determine whether that symbol is genuinely part of the definition model.

If yes, migrate only the minimum directly related contract after documenting why it belongs in this package.

If no, remove or neutralize the dependency while preserving the definition metadata API.

## 12.2 PHPDoc-only dependency

Replace a framework-specific PHPDoc type such as:

```php
class-string<SomeExternalRuntimeInterface>
```

with:

```php
class-string
```

when the definition only stores an opaque class name.

Do not add an external package solely to preserve PHPDoc specificity.

## 12.3 Default class string

When an external class is merely a default implementation:

* remove the default;
* make the value nullable when appropriate;
* keep the setter/getter metadata API;
* document the change.

## 12.4 Cannot preserve independently

When a behavior cannot be preserved without importing a substantial unrelated subsystem:

1. Do not implement a speculative replacement.
2. Add a focused test for the remaining supported behavior.
3. Record the missing behavior in `docs/phase-2-notes.md`.
4. Keep the public method only when it can still behave coherently.
5. Report the issue at completion.

Do not expand Phase 2 into a query, ORM, or framework extraction.

---

# 13. Phase 1 support classes

Do not refactor migrated definitions to use:

```text
ON\Data\Support\DefinitionNode
ON\Data\Support\Dot
```

in this phase.

The Phase 1 support classes remain available but mostly unused until Phase 3.

Do not delete them.

Do not rename them.

Do not alter their reference-binding semantics.

Only fix a Phase 1 support defect when it independently causes a failing Phase 1 test.

---

# 14. Public API inventory

Create:

```text
docs/phase-2-public-api.md
```

For every migrated public class/interface, record:

* fully qualified class name;
* class/interface/trait type;
* parent class;
* implemented interfaces;
* used traits;
* public constructor;
* public methods;
* parameter types and defaults;
* return types;
* public properties;
* intentional deviations from the Overnight source.

Generate as much of this inventory as practical through reflection or a small development script.

Do not include private implementation details unless needed to explain a compatibility deviation.

The API inventory must make Phase 3 regressions visible.

---

# 15. Source parity check

Add a development-only script or test that compares the source manifest with the migrated target tree.

It must verify that every source PHP file under:

```text
src/ORM/Definition/
```

has a corresponding target PHP file under:

```text
src/Definition/
```

after applying the namespace/path mapping.

Allowed exceptions must be listed explicitly.

At the end of Phase 2, expected exceptions should be zero unless a source file genuinely cannot belong in the standalone definition package.

Do not silently omit files.

---

# 16. Characterization tests

The purpose of Phase 2 tests is to preserve current behavior before refactoring storage.

Tests should use the new namespace:

```php
ON\Data\Definition
```

Do not test future behavior.

## 16.1 Registry tests

Cover:

* creating a collection;
* collection name assignment;
* table defaulting to collection name;
* retrieving a collection;
* missing collection returning the current expected result;
* passing a CollectionInterface into `getCollection()`;
* registering an existing collection;
* collection replacement behavior if the same name is defined again;
* definition file-location tracking;
* `getDefinitionFiles()`;
* multiple collections;
* public collection map behavior if currently exposed.

Do not change `getCollection()` to throw yet.

## 16.2 Collection tests

Cover every current setter/getter pair, including where present:

* `name`;
* `table`;
* `database`;
* `entity`;
* `parentCollection`;
* `scope`;
* `repository`;
* `mapper`;
* `source`;
* `note`;
* `description`;
* `hidden`;
* metadata;
* fields;
* relations;
* visible fields;
* visible columns;
* field-name lookup by column;
* row mapping from columns;
* visible-row mapping;
* Registry access;
* `end()`.

Test the two standalone changes:

```php
getMapper() === null
getSource() === null
```

before explicit configuration.

## 16.3 Field tests

Cover every current setter/getter pair, including where present:

* name;
* alias;
* type;
* missing type behavior;
* column;
* required;
* searchable;
* sensible;
* hidden behavior caused by sensible fields;
* default;
* cast-default;
* generated-from-relation;
* validation;
* validation messages;
* description;
* typecast;
* schema metadata;
* display;
* interface;
* metadata;
* primary key;
* filterable;
* auto increment;
* nullable;
* unique;
* index;
* comment;
* numeric precision;
* all other methods present in the actual source;
* `end()`.

The test inventory must be based on the real class, not only this list.

## 16.4 FieldMap tests

Cover:

* empty map;
* set;
* get;
* has;
* duplicate behavior;
* missing-field behavior;
* iteration;
* column lookup;
* field-name lookup by column;
* insertion order;
* clone behavior if supported;
* return types.

## 16.5 Relation base tests

Cover:

* parent;
* name;
* target collection name;
* target collection resolution;
* nullable;
* where;
* orderBy;
* cascade;
* load strategy;
* loader;
* metadata;
* display;
* interface;
* cardinality;
* junction status;
* `end()`.

## 16.6 Composite relation-key tests

Preserve and test the current branch behavior:

* scalar inner key;
* scalar outer key;
* array inner keys;
* array outer keys;
* normalized key order;
* empty key rejection;
* empty-name rejection;
* duplicate key rejection;
* inner/outer arity mismatch;
* singular getter behavior;
* singular field getter behavior;
* target primary-key arity validation;
* delayed configuration where the target collection is not registered yet.

Do not alter this behavior to use the future collection-owned PK list.

## 16.7 Relation subclass tests

Test every relation subclass separately:

```text
BelongsToRelation
HasOneRelation
HasManyRelation
FirstOfManyRelation
M2MRelation
M2MThrough
```

For each, cover:

* constructor and parent;
* cardinality;
* junction status;
* relation-specific metadata;
* generated fields;
* key defaults or inference;
* relation-specific helpers;
* cloning if applicable.

Use the actual public methods found in the source.

## 16.8 RelationMap tests

Cover:

* empty map;
* set;
* get;
* has;
* duplicate behavior;
* missing behavior;
* iteration;
* insertion order;
* clone behavior.

## 16.9 PrimaryKeyDefinition tests

Cover current behavior, including:

* simple PK;
* composite PK;
* field names;
* columns;
* `isComposite()`;
* input normalization;
* record extraction;
* field-name and column-name inputs;
* positional input where supported;
* URL ID parsing/encoding where supported;
* invalid input.

These tests are temporary characterization tests for Phase 4.

## 16.10 PrimaryKeyValue tests

Cover current behavior, including:

* collection access;
* values;
* per-field access;
* completeness;
* simple URL ID;
* composite URL ID;
* conversion behavior;
* missing components.

Do not change its API yet.

## 16.11 Display tests

Test every class under:

```text
Definition/Display
```

At minimum verify:

* construction;
* configuration methods;
* getters;
* defaults;
* inheritance;
* cloning if relevant.

## 16.12 Interface-definition tests

Test every class under:

```text
Definition/Interface
```

At minimum verify:

* construction;
* options/configuration methods;
* getters;
* defaults;
* inheritance;
* relation-specific interface metadata.

## 16.13 Metadata tests

Cover:

* `MetadataMap`;
* set;
* get;
* missing key;
* overwrite;
* trait integration with Collection;
* trait integration with Field;
* trait integration with Relation.

## 16.14 Schema tests

Cover:

* `SchemaInterface`;
* `SchemaTrait`;
* all current schema properties and methods;
* primary-key flag behavior;
* auto-increment;
* filtering behavior changed by PK configuration;
* defaults.

---

# 17. Existing Overnight tests

Search the source repository for all tests referencing:

```text
ON\ORM\Definition
ORM\Definition
Registry
Collection
FieldMap
RelationMap
PrimaryKeyDefinition
PrimaryKeyValue
```

Port relevant tests when they test the definition subsystem directly.

Change their namespaces and imports to the new package.

Do not port tests that primarily test:

* REST;
* query execution;
* Cycle ORM;
* mutation handlers;
* database integration;
* framework bootstrapping.

When a source test mixes definition behavior with unrelated runtime code, extract only the definition behavior into a new focused characterization test.

Record:

* tests copied unchanged except namespace;
* tests adapted;
* tests not copied and why.

Put this information in:

```text
docs/phase-2-notes.md
```

---

# 18. Test fixtures

Create reusable fixtures only when they reduce duplication.

Suggested helpers:

```text
tests/Fixture/CreatesDefinitionRegistry.php
tests/Fixture/CustomField.php
tests/Fixture/CustomRelation.php
```

A custom relation fixture should prove that relation subclasses remain pluggable under the extracted namespace.

Do not implement runtime relation handlers.

Do not add database fixtures.

---

# 19. Static analysis

Run static analysis against all migrated files.

Fix real issues where possible without redesigning behavior.

Permitted fixes include:

* precise array PHPDoc;
* missing generic annotations;
* nullable property corrections required by actual behavior;
* corrected class-string annotations;
* impossible return annotations;
* namespace/import mistakes.

Do not:

* add a broad baseline hiding all migrated problems;
* add large `ignoreErrors` patterns;
* convert all types to `mixed`;
* change public behavior merely to satisfy static analysis;
* redesign maps or definitions.

When a legacy API is inherently inconsistent, add the narrowest targeted annotation and record it in the notes for later correction.

---

# 20. Coding style

Apply the target repository’s existing coding style.

Mechanical formatting changes are allowed.

Do not mix formatting changes with unnecessary semantic rewrites.

Every migrated file must:

* declare strict types if the source does;
* use the target namespace;
* follow target formatting;
* contain no dead imports;
* pass the configured style check.

---

# 21. Dependency guard

Update or add a CI/test guard ensuring production source does not import:

```text
Cycle\
Doctrine\
ON\ORM\
ON\RestApi\
```

The namespace migration itself means there must also be no production reference to:

```text
ON\ORM\Definition\
```

String occurrences in documentation and source-migration notes are allowed.

The guard should inspect production PHP code, not documentation.

If opaque metadata contains a class string supplied by application code at runtime, that is allowed. Hard-coded old framework defaults are not.

---

# 22. Documentation

Create:

```text
docs/phase-2-notes.md
docs/phase-2-source-manifest.md
docs/phase-2-public-api.md
```

## `phase-2-notes.md`

Include:

1. Source repository URL.
2. Source branch.
3. Source commit hash.
4. Target starting commit hash.
5. Files copied.
6. Tests copied.
7. Tests newly created.
8. Namespace transformations.
9. Complete external dependency table.
10. Mapper/source default changes.
11. Static-analysis compromises.
12. Existing behaviors that seem questionable but were preserved.
13. Behaviors deferred to Phase 3.
14. Behaviors deferred to Phase 4.
15. Behaviors deferred to Phase 5.
16. Any source/target discrepancy.

## `phase-2-source-manifest.md`

Include every migrated source file and declaration.

## `phase-2-public-api.md`

Include the migrated public API snapshot.

Update the README only to state that the current package contains the mechanically extracted definition subsystem.

Do not document the future master-array Registry as implemented.

---

# 23. Explicit non-goals

Do not:

* convert Registry to one master array;
* make Registry extend DefinitionNode;
* make Collection extend DefinitionNode;
* make Field extend DefinitionNode;
* make Relation extend DefinitionNode;
* bind wrappers to array references;
* add `Registry::all()` for complete definitions;
* add definition cache restoration;
* implement wrapper caching;
* add collection-level `primaryKey()`;
* remove field-level primary keys;
* remove `PrimaryKeyDefinition`;
* remove `PrimaryKeyValue`;
* add `Key`;
* add `DefinitionInterface`;
* generalize Field parents;
* generalize Relation parents;
* add views;
* add queries;
* add expressions;
* add aggregates;
* add relation handlers;
* add mapping execution;
* add FieldType;
* add representations;
* add database adapters;
* add mutation code;
* add Unit of Work;
* add REST or GraphQL integration;
* modify the original Overnight repository.

Do not start Phase 3 automatically.

---

# 24. Implementation order

Follow this order.

## Step 1 — Verify Phase 1

1. Read Phase 1 notes.
2. Run existing quality commands.
3. Record the starting state.

## Step 2 — Inventory source

1. Pin the source commit.
2. Generate the file manifest.
3. Generate the external dependency inventory.
4. Locate relevant source tests.

## Step 3 — Mechanical copy

1. Copy all files recursively.
2. Change namespaces.
3. Change internal imports.
4. Run PHP syntax checks.

## Step 4 — Remove extraction blockers

1. Remove Cycle mapper import/default.
2. Remove Overnight query source import/default.
3. Resolve only other unavoidable external references.
4. Document every deviation.

## Step 5 — Make production code compile

1. Run Composer autoload generation.
2. Run static analysis.
3. Fix namespace and type errors.
4. Avoid architectural refactors.

## Step 6 — Port characterization tests

1. Port relevant source tests.
2. Add missing focused tests.
3. Build custom field/relation fixtures.
4. Confirm current object-backed behavior.

## Step 7 — API and parity documentation

1. Complete the public API inventory.
2. Run source parity checks.
3. Complete dependency notes.

## Step 8 — Quality checks

Run every configured command.

Stop after all checks and documentation are complete.

---

# 25. Required quality commands

Run the actual configured equivalents of:

```bash
composer validate --strict
composer install
composer dump-autoload
composer test
composer analyse
composer check-style
```

Also run a syntax check over production files, for example:

```bash
find src -name '*.php' -print0 \
    | xargs -0 -n1 php -l
```

Run the production namespace/dependency guard.

All commands must pass.

Do not claim a command passed unless it was actually executed successfully.

---

# 26. Definition of done

Phase 2 is complete only when:

1. Every PHP file under the source definition tree has been copied or explicitly accounted for.
2. The new namespace is `ON\Data\Definition`.
3. Internal definition references use the new namespace.
4. Production code contains no runtime dependency on Overnight.
5. Production code contains no runtime dependency on Cycle.
6. Production code contains no runtime dependency on Doctrine.
7. The Cycle mapper default has been removed.
8. The Overnight query source default has been removed.
9. The current object-backed Registry behavior remains.
10. Collection still owns FieldMap and RelationMap objects.
11. Field-level primary keys still work.
12. `PrimaryKeyDefinition` still exists.
13. `PrimaryKeyValue` still exists.
14. Existing composite relation-key behavior remains.
15. Fields still return Collection from `end()`.
16. Relations still return Collection from `end()`.
17. No ViewDefinition exists.
18. No master-array migration has started.
19. Relevant source tests have been ported.
20. Missing characterization tests have been added.
21. All migrated public APIs are documented.
22. All external dependencies are documented.
23. Source and target file parity is verified.
24. PHPUnit passes.
25. Static analysis passes.
26. Coding-style checks pass.
27. Production dependency guards pass.
28. Phase 1 tests still pass.
29. Documentation accurately records deviations.
30. Phase 3 has not been started.

---

# 27. Final response

At completion, report:

* source commit used;
* target starting and ending commits;
* files copied;
* files omitted, if any, with reasons;
* namespace changes;
* external dependencies found;
* how each external dependency was handled;
* public API deviations;
* tests ported;
* tests added;
* quality commands executed;
* command results;
* unresolved concerns for Phase 3;
* unresolved concerns for Phase 4;
* unresolved concerns for Phase 5.

Do not begin the master-array refactor.

Stop after Phase 2.
