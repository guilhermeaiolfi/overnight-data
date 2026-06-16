# Implementation Task — Phase 6: Stabilize and Finalize the Definition Foundation

## Objective

Finalize the standalone `ON\Data` definition foundation after the extraction, master-array migration, primary-key redesign, and introduction of `ViewDefinition`.

This phase is a stabilization, validation, documentation, and release-readiness phase.

It must:

1. Verify and commit the completed Phase 5 implementation.
2. Audit the public API for accidental infrastructure exposure.
3. Confirm that all definition state is represented only in the Registry master array.
4. Verify the internal wrapper construction and rebinding architecture.
5. Strengthen tests around extension points, restoration, and invalid definitions.
6. Remove dead extraction artifacts and obsolete compatibility code.
7. Raise and lock PHPStan at level 1.
8. Finalize public documentation for the definition subsystem.
9. Prepare the package for its first definitions-only release.

Do not implement queries, semantic view fields, FieldType execution, persistence, adapters, or ORM behavior.

---

# 1. Required starting state

Before modifying production code:

1. Inspect the current working tree.
2. Read:

   * `docs/phase-1-notes.md`
   * `docs/phase-2-notes.md`
   * `docs/phase-3-notes.md`
   * `docs/phase-3-storage-format.md`
   * `docs/phase-4-notes.md`
   * `docs/phase-4-key-format.md`
   * `docs/phase-5-notes.md`
   * `docs/phase-5-view-format.md`
   * the current public API inventory
3. Preserve all unrelated user-owned changes under `docs/1-definitions/`.
4. Run:

   ```bash
   composer test
   composer analyse
   vendor/bin/phpstan analyse --configuration phpstan.neon.dist --level=1
   composer check-style
   ```
5. Record the actual results.
6. Fix any Phase 5 regression before proceeding.
7. Commit Phase 5 separately.
8. Record the Phase 5 ending commit.
9. Create:

   ```text
   docs/phase-6-notes.md
   ```

Do not begin stabilization while Phase 5 remains uncommitted.

---

# 2. Scope

The finalized definition architecture must be:

```text
Registry
├── collections
│   └── Collection
│       ├── fields
│       │   └── Field
│       ├── relations
│       │   └── Relation subclasses
│       └── metadata/display/interface/schema data
└── views
    └── ViewDefinition
        ├── fields
        │   └── ViewField
        ├── relations
        │   └── compatible Relation subclasses
        └── metadata/source data
```

The Registry master array remains the sole source of truth.

Runtime wrappers provide:

* fluent configuration;
* typed getters;
* class-specific behavior;
* `end()` navigation;
* extension points.

Runtime wrappers, caches, Registry references, and construction machinery must not appear in exported definition data.

---

# 3. Explicit non-goals

Do not implement:

* query objects;
* QuerySpec;
* SourceRef;
* FieldRef;
* RelationRef;
* expression ASTs;
* aggregates;
* semantic `ViewField::from()`;
* computed view fields;
* view cardinality;
* view identity;
* source-cycle execution validation;
* relation loading;
* relation handlers;
* database adapters;
* SQL generation;
* FieldType execution;
* Representation execution;
* Mapper execution;
* `map($query)->to(...)`;
* direct mutations;
* ValueRef;
* operation plans;
* persistence;
* Unit of Work;
* entity mapping;
* REST or GraphQL integration;
* caching beyond Registry definition-array caching;
* schema migrations.

Do not begin the next milestone automatically.

---

# 4. Public API audit

Generate a fresh public API inventory using reflection or a reliable source-inspection script.

For every public production class, record:

* namespace;
* class/interface/trait;
* parent;
* implemented interfaces;
* public constructor;
* public properties;
* public methods;
* parameter and return types;
* `@internal` status.

Review the inventory for infrastructure leaks.

The following must not appear as application-facing public APIs:

```text
bindDefinitionArray
bindItems
hydrateFromArray
restoreFromArray
setDefinitionReference
getDefinitionArrayReference
```

Equivalent infrastructure method names must also be identified.

If a technically public infrastructure method is unavoidable because of PHP construction mechanics:

1. Mark it `@internal`.
2. Place it under an internal interface or class where practical.
3. Exclude it from README examples and public API documentation.
4. Add a test proving its `@internal` marker exists.
5. Document why it cannot yet be non-public.

Do not redesign working reference-binding mechanics solely for aesthetic reasons.

---

# 5. Constructor audit

Review constructors for:

* Registry;
* Collection;
* ViewDefinition;
* Field;
* ViewField;
* every Relation subclass;
* Display classes;
* Interface-definition classes;
* `M2MThrough`;
* `DefinitionFactory`.

Application-facing constructors must not require:

* raw nested definition arrays;
* array references;
* Registry storage paths;
* hydration flags;
* internal wrapper caches.

Internal restoration must happen through `DefinitionFactory` and protected `DefinitionNode` infrastructure.

Where constructor signatures are still infrastructure-oriented:

* simplify them if this can be done safely;
* otherwise mark internal construction paths clearly;
* preserve extension subclass construction.

Do not break custom subclasses merely to produce cosmetically minimal constructors.

Add constructor-extension tests for supported custom subclasses.

---

# 6. DefinitionFactory stabilization

`ON\Data\Definition\Internal\DefinitionFactory` is the only reconstruction authority.

Audit its responsibilities.

It should handle:

* Collection wrappers;
* ViewDefinition wrappers;
* Field and ViewField wrappers;
* Relation subclasses;
* Display subclasses;
* Interface-definition subclasses;
* `M2MThrough`;
* class-discriminator validation;
* internal rebinding.

Requirements:

1. The class must be marked `@internal`.
2. It must not store definition metadata independently.
3. It must not copy arrays during wrapper restoration.
4. It must reject invalid class discriminators before instantiation.
5. It must produce focused exceptions.
6. Its API must not be advertised in the README.
7. It must not depend on Cycle, Doctrine, REST, or ORM code.
8. It must not use unrestricted arbitrary class instantiation.

Avoid creating separate factories unless a concrete complexity problem justifies one.

---

# 7. DefinitionNode stabilization

Audit `ON\Data\Support\DefinitionNode`.

It should provide only generic array-backed wrapper behavior, such as:

* nested get;
* nested set;
* existence check;
* array export;
* iteration;
* JSON serialization;
* protected/internal array binding.

It must not know about:

* collections;
* views;
* primary keys;
* fields;
* relations;
* Cycle;
* Doctrine;
* queries;
* persistence.

Verify:

* binding is not application-facing;
* cloned nodes do not retain hidden references;
* root nodes own their data;
* nested nodes bind to Registry data;
* `all()` never exposes runtime caches;
* reading values does not mutate definitions.

Remove dead support methods that are neither used nor part of the intended generic support API.

Do not add speculative generic features.

---

# 8. Registry normalization audit

Review Registry constructor normalization.

It must canonicalize only structural necessities:

```text
collections
views
class discriminators
name
fields
relations
metadata
primaryKey
```

Requirements:

* normalization is deterministic;
* normalization happens during construction, not lazily during getters;
* read-only access after construction does not mutate `all()`;
* canonical arrays round-trip exactly;
* old supported definition formats normalize once;
* conflicting primary-key formats still throw;
* collection/view name conflicts still throw;
* invalid class discriminators throw;
* missing relation class discriminators throw when type cannot be inferred.

Create tests comparing:

```php
$normalized = (new Registry($legacy))->all();
$restored = (new Registry($normalized))->all();

assert($restored === $normalized);
```

This proves normalization is idempotent.

---

# 9. Plain-data validation

Add or consolidate a test utility that recursively verifies Registry data contains only:

* arrays;
* strings;
* integers;
* floats;
* booleans;
* null.

Explicitly reject:

* objects;
* resources;
* closures.

Run this against comprehensive definitions containing:

* collections;
* views;
* composite primary keys;
* custom classes stored as class strings;
* fields;
* relations;
* M2M metadata;
* display metadata;
* interface metadata;
* schema metadata;
* arbitrary extension metadata using valid plain data.

Do not add automatic serialization of arbitrary objects.

If metadata currently accepts an object, the definition may be configured in memory, but export/cache validation must reject it with a focused exception or clearly documented validation failure.

Select one consistent policy and test it.

---

# 10. Definition name rules

Finalize and test root definition-name behavior.

Collections and views use one logical namespace.

Reject:

* empty names;
* whitespace-only names;
* collection/view conflicts;
* restored arrays containing conflicts.

Preserve valid names currently supported by Overnight unless they create unavoidable ambiguity.

Do not introduce restrictive identifier rules such as requiring SQL-compatible names. Definitions are logical application names.

Document whether names may contain:

* dots;
* dashes;
* spaces;
* namespace-like separators.

If dots conflict with Dot-path storage, ensure names are accessed as literal array keys rather than incorrectly parsed as paths.

Add tests for any supported special characters.

---

# 11. Primary-key final verification

Do not redesign `Key`.

Verify the Phase 4 behavior remains correct:

* collection-level primary-key storage;
* simple key;
* composite key;
* canonical field order;
* positional input;
* associative input;
* column-name compatibility;
* record extraction;
* strict type distinction;
* deterministic hash;
* Registry round-trip equality;
* field-level PK metadata absent;
* `Field::isPrimaryKey()` derived;
* ViewField never primary key.

Add extension tests involving a custom Collection subclass and composite `Key`.

No FieldType normalization should be introduced.

Document that values must already be canonical scalar PHP values.

---

# 12. ViewDefinition final verification

Verify the Phase 5 structural behavior:

* view creation;
* source by name;
* source by definition;
* source to collection;
* source to another view;
* missing source behavior;
* metadata;
* ViewField;
* generic compatible relation;
* wrapper identity;
* clone detachment;
* master-array round trip;
* class discriminator restoration.

Do not add semantic execution behavior.

Document explicitly:

> A ViewDefinition currently defines only the structural business-model container. Field source expressions, aggregates, cardinality, and execution are not implemented yet.

---

# 13. Relation extension audit

Verify relation architecture remains class-based and extensible.

Test a custom relation subclass that:

* stores custom plain-data metadata;
* is registered through the existing generic relation API;
* survives Registry round trip;
* returns its correct parent from `end()`;
* works under a Collection;
* works under a ViewDefinition only when explicitly compatible.

Collection-only relation subclasses must reject ViewDefinition parents through `InvalidRelationParentException`.

Do not add a central enum or switch over all relation classes.

Do not add query or mutation handlers yet.

Document the future extension expectation:

```text
Relation definition class
Future query handler
Optional future mutation handler
```

Only the definition class exists in this milestone.

---

# 14. Field and ViewField extension audit

Verify:

* standard Collection fields use `Field`;
* standard View fields use `ViewField`;
* custom Field subclasses survive round trip;
* custom ViewField subclasses survive round trip;
* field maps preserve insertion order;
* wrapper identity remains stable;
* replacement invalidates only the replaced wrapper;
* unrelated wrappers remain stable.

Document storage-specific methods inherited by ViewField as structurally available but without query/storage semantics.

Do not redesign the Field hierarchy in this phase.

---

# 15. Clone behavior audit

Run explicit tests for:

* Registry if cloneable;
* Collection;
* ViewDefinition;
* Field;
* ViewField;
* Relation;
* Display;
* Interface definition;
* FieldMap;
* RelationMap;
* `M2MThrough`.

For each class, document whether clone behavior is:

* detached copy;
* unsupported;
* shared immutable object.

No clone may retain accidental hidden references to the original Registry array.

When detached clones are mutated, the original Registry must remain unchanged.

Do not add cloning support to classes that intentionally do not support it.

---

# 16. Dead code and extraction artifacts

Search production source for:

```text
ON\ORM
ON\RestApi
Cycle\
Doctrine\
PrimaryKeyDefinition
PrimaryKeyValue
bindDefinitionArray
```

Also search for:

* unused imports;
* obsolete comments;
* migration-only compatibility branches that are no longer needed;
* stale PHPDoc names;
* references to the original Overnight repository structure;
* old source/mapper defaults;
* old relation-loader defaults;
* temporary Phase 2 compatibility code.

Remove dead code only when tests prove it is no longer needed.

Do not remove legacy public behavior merely because it looks unusual.

Preserve intentionally retained APIs such as current display/interface method names unless this phase explicitly identifies them as impossible to support.

---

# 17. Exception audit

Review definition exceptions.

Ensure focused exceptions exist and are used consistently for:

* missing collection;
* missing view;
* missing definition;
* collection/view name conflict;
* foreign Registry definition;
* missing field;
* missing relation;
* invalid relation parent;
* invalid class discriminator;
* primary key not defined;
* invalid key;
* composite simple-only operation;
* conflicting old/new PK metadata.

Avoid multiple exception classes representing the same condition.

Do not perform a broad exception namespace redesign.

Document the final exception map.

---

# 18. PHPStan

Phase 5 must first verify level 1 actually passes.

Set:

```text
level: 1
```

in `phpstan.neon.dist`.

Required:

```bash
composer analyse
```

must run level 1 and pass.

Also run level 2 informationally:

```bash
vendor/bin/phpstan analyse \
    --configuration phpstan.neon.dist \
    --level=2
```

Level 2 does not need to pass during this phase.

Record:

* number of level-2 errors;
* categories;
* files most affected;
* whether errors come from retained legacy APIs or new code.

Do not:

* lower below level 1;
* add a baseline;
* add broad ignores;
* convert APIs to `mixed` merely to silence analysis.

Fix level-1 issues fully.

---

# 19. Test organization

Reorganize tests only where it improves clarity without losing history.

Suggested structure:

```text
tests/
├── Architecture/
├── Definition/
│   ├── Collection/
│   ├── Field/
│   ├── Relation/
│   ├── View/
│   └── Registry/
├── Key/
├── Support/
└── Fixture/
```

Reorganization is optional.

Do not spend the phase moving every test only for aesthetics.

Ensure test names describe behavior rather than implementation phases where possible.

Phase-specific architecture tests may remain when they guard intentional migration decisions.

---

# 20. Documentation

Create:

```text
docs/definitions.md
docs/extending-definitions.md
docs/phase-6-notes.md
```

Update:

```text
README.md
docs/phase-3-storage-format.md
docs/phase-4-key-format.md
docs/phase-5-view-format.md
docs/phase-2-public-api.md
```

## `docs/definitions.md`

Document the implemented public API:

* Registry;
* collections;
* fields;
* relations;
* primary keys;
* Key;
* views;
* metadata;
* fluent `end()` usage;
* array export and restoration.

Include examples:

```php
$registry
    ->collection('users')
        ->primaryKey('id')
        ->field('id', 'integer')
            ->end()
        ->end();
```

```php
$registry
    ->view('user_summary')
        ->source('users')
        ->field('id', 'integer')
            ->end()
        ->end();
```

Do not document unimplemented semantic-view APIs.

## `docs/extending-definitions.md`

Document supported extension points:

* custom Collection subclass;
* custom Field subclass;
* custom ViewDefinition subclass;
* custom ViewField subclass;
* custom Relation subclass;
* custom Display/Interface classes where supported;
* class discriminators;
* plain-data requirement;
* round-trip expectations.

Do not document `DefinitionFactory` or binding methods as public extension APIs.

## README

Present the package honestly as:

> The definition foundation of a metadata-driven PHP data layer.

Clearly mark query execution, persistence, and ORM support as future work.

---

# 21. Release-readiness

Prepare the package for a definitions-only pre-release.

Do not publish or tag automatically.

Add or verify:

* license;
* package description;
* keywords;
* minimum PHP version;
* autoload rules;
* development scripts;
* README installation instructions;
* changelog or release notes;
* no accidental path repositories;
* no local absolute paths;
* no development-only production imports.

Suggested version milestone:

```text
0.1.0-dev
```

or a first alpha such as:

```text
0.1.0-alpha.1
```

Do not change the package version if the project does not currently declare one in `composer.json`.

Create:

```text
docs/release-0.1-checklist.md
```

Do not create a Git tag.

---

# 22. Quality commands

Run all applicable commands:

```bash
composer validate --strict
composer install
composer dump-autoload
composer test
composer analyse
composer check-style
```

Run PHP syntax checks over all production files.

Run architecture/dependency guards.

Run PHPStan level 2 informationally.

All configured required commands must pass.

Do not report success for commands that were not executed.

---

# 23. Implementation order

## Step 1 — Accept Phase 5

1. Run actual Phase 5 quality checks.
2. Fix regressions.
3. Commit Phase 5.
4. Record baseline.

## Step 2 — Public/internal API audit

1. Generate API inventory.
2. Find construction/binding leaks.
3. Mark or remove infrastructure APIs.
4. Test supported custom construction.

## Step 3 — Storage and normalization audit

1. Verify canonical root.
2. Verify idempotent normalization.
3. Verify plain data.
4. Verify no-read mutation.
5. Add invalid-input tests.

## Step 4 — Extension audit

1. Test custom collection/view classes.
2. Test custom field classes.
3. Test custom relation classes.
4. Test display/interface subclasses.
5. Document extension rules.

## Step 5 — Behavior verification

1. Re-run primary-key suite.
2. Re-run view suite.
3. Re-run relation suite.
4. Re-run clone suite.
5. Re-run round-trip suite.

## Step 6 — Cleanup

1. Remove dead imports/code.
2. Remove extraction artifacts.
3. Consolidate exceptions where clearly duplicated.
4. Preserve legacy public behavior.

## Step 7 — Static analysis

1. Configure PHPStan level 1.
2. Make level 1 pass.
3. Run level 2 informationally.
4. Record results.

## Step 8 — Documentation and release preparation

1. Write public definitions guide.
2. Write extension guide.
3. Update README.
4. Create release checklist.
5. Regenerate API inventory.

## Step 9 — Final verification

1. Run every quality command.
2. Record exact results.
3. Commit Phase 6.
4. Stop.

---

# 24. Definition of done

Phase 6 is complete only when:

1. Phase 5 has a committed ending hash.
2. Actual Phase 5 quality results are recorded.
3. Registry definitions remain one master plain array.
4. Normalization is idempotent.
5. Read-only access does not mutate canonical data.
6. Complete definitions round-trip exactly.
7. Public rebinding methods are absent.
8. Hydration infrastructure is internal.
9. DefinitionFactory is internal and validated.
10. DefinitionNode remains generic.
11. Custom Collection subclasses round-trip.
12. Custom ViewDefinition subclasses round-trip.
13. Custom Field subclasses round-trip.
14. Custom ViewField subclasses round-trip.
15. Custom Relation subclasses round-trip.
16. Invalid relation parents throw focused exceptions.
17. Primary-key behavior remains correct.
18. Key behavior remains correct.
19. ViewDefinition structural behavior remains correct.
20. Clone behavior has no hidden shared references.
21. Registry exports contain only plain data.
22. No Cycle dependency exists.
23. No Doctrine dependency exists.
24. No Overnight production dependency exists.
25. No old ORM/REST namespace remains.
26. No `PrimaryKeyDefinition` remains.
27. No `PrimaryKeyValue` remains.
28. No public `bindDefinitionArray()` remains.
29. Public API inventory is current.
30. PHPStan configured level is 1.
31. PHPStan level 1 passes.
32. PHPStan level 2 results are documented.
33. PHPUnit passes.
34. Style checks pass.
35. Dependency guards pass.
36. Public definition documentation exists.
37. Extension documentation exists.
38. Release checklist exists.
39. No query or persistence feature was added.
40. The next milestone was not started.

---

# 25. Final response

At completion, report:

* Phase 5 ending commit;
* Phase 6 starting and ending commits;
* files changed;
* API leaks found and resolved;
* final constructor/hydration architecture;
* DefinitionFactory status;
* normalization changes;
* extension tests added;
* dead code removed;
* exception changes;
* final PHPStan configured level;
* PHPStan level-1 result;
* PHPStan level-2 result;
* test count and assertions;
* every quality command and result;
* documentation created;
* release-readiness status;
* deviations from this specification;
* unresolved concerns for the query/semantic-view milestone.

Do not begin the next milestone.

Stop after Phase 6.
