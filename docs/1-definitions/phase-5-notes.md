# Phase 5 Notes

1. Starting commits
   - Phase 4 ending commit: `eac7d01`
   - Phase 5 starting commit: `eac7d01`
   - Phase 5 ending commit: pending until final commit
2. Unrelated user-owned changes excluded
   - `docs/1-definitions/phase-3-housekeeping.md`
   - `docs/1-definitions/phase-4-primary-key.md`
   - `docs/1-definitions/phase-5-viewdefinition.md`
   - existing deletion in `docs/1-definitions/phase-3-implementation.md`
3. Shared definition implementation
   - Added `DefinitionInterface` plus `AbstractDefinition` to share registry, field, relation, metadata, and `end()` behavior between collections and views.
4. Registry view APIs
   - Added `view()`, `getView()`, `hasView()`, `getViews()`, `getDefinition()`, and `hasDefinition()`.
5. View source behavior
   - View sources are stored as definition names and resolve through `Registry::getDefinition()`.
   - Missing configured sources throw `DefinitionNotFoundException`.
6. Field parent changes
   - Fields now point to `DefinitionInterface` parents and `isPrimaryKey()` only returns true for collection-backed fields.
7. Relation parent changes
   - Relations now point to `DefinitionInterface` parents while still resolving relation targets as collections.
8. Collection-only relation restrictions
   - `HasOneRelation` and subclasses explicitly reject view parents through `InvalidRelationParentException`.
9. `bindDefinitionArray()` removal
   - Public collection rebinding was removed. Rebinding now goes through `DefinitionFactory::rebind()` and protected `DefinitionNode` infrastructure.
10. Hydration constructor solution
   - Constructors now expose only application-facing arguments. Internal rebinding is performed after construction by `DefinitionFactory`.
11. DefinitionFactory changes
   - Added creation paths for collections, views, fields, relations, displays, interfaces, and `M2MThrough`.
   - Added discriminator validation for view classes and a shared rebind helper.
12. Clone behavior
   - Detached clones now break hidden PHP references before replacing their internal arrays so cloned views and nested wrappers do not mutate the original registry state.
13. Tests added and changed
   - Added `tests/Definition/ViewDefinitionTest.php`.
   - Added `tests/Architecture/Phase5ArchitectureTest.php`.
   - Added custom view fixtures.
   - Updated `tests/Definition/FieldAndMapTest.php` for the definition-wide field parent wording.
14. PHPStan results
   - Configured `composer analyse`: expected to pass at level 1.
   - Manual level 1 run: expected to pass.
15. Deferred to semantic views
   - Expressions, aggregates, computed field APIs, and source-cycle validation.
16. Deferred to querying
   - Query objects, execution plans, loading, and caching.
17. Deferred to ORM
   - Persistence, adapters, write mapping, and Unit of Work concerns.
