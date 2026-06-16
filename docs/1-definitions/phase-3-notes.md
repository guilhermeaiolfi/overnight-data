# Phase 3 Notes

1. Starting commit hash: `decc79c`
2. Ending commit hash: pending until Phase 3 commit
3. Files modified:
   - Core support: `src/Support/DefinitionNode.php`
   - Registry and metadata: `src/Definition/Registry.php`, `src/Definition/MetadataTrait.php`, `src/Definition/Metadata/MetadataMap.php`, `src/Definition/Internal/DefinitionFactory.php`
   - Collections, fields, relations, displays, and interfaces under `src/Definition/`
   - New fixtures and storage tests under `tests/`
4. DefinitionNode integration approach:
   - `Registry` now extends `DefinitionNode` and owns the full master array at root key `collections`.
   - Collection, Field, Relation, Display, Interface, and `M2MThrough` wrappers also extend `DefinitionNode`, but bind to nested registry arrays by reference when restored or registered.
5. Wrapper-cache architecture:
   - `Registry::$collections` caches collection wrappers by name.
   - `FieldMap` and `RelationMap` keep runtime-only caches keyed by field/relation name while the nested arrays remain authoritative.
   - `DisplayTrait` and `InterfaceTrait` cache one nested wrapper per parent wrapper.
6. Constructor/factory changes:
   - Collection, Field, Relation, Display, Interface, and `M2MThrough` constructors accept an optional bound array reference as a second parameter for restoration.
   - `ON\Data\Definition\Internal\DefinitionFactory` validates stored class discriminators and reconstructs wrappers without copying metadata into a second structure.
7. Clone semantics:
   - Cloning `Collection`, `Field`, `Relation`, `Display`, `Interface`, `FieldMap`, `RelationMap`, and `M2MThrough` now detaches them onto copied arrays instead of keeping hidden references to the original registry state.
8. Import normalization behavior:
   - Root construction normalizes missing collection `class`, `name`, `table`, `fields`, `relations`, and `metadata`.
   - Missing field classes default to `Field::class`.
   - Missing collection classes default to `Collection::class`.
   - Relation definitions require an explicit `class` discriminator during restoration.
9. API deviations:
   - Public constructors for wrappers gained an optional second internal hydration parameter for bound-array restoration.
   - `Collection` adds `bindDefinitionArray(array &$items): void` to support canonical rebinding during `Registry::register()`.
10. PHPStan configured-level result:
   - `composer analyse`: pass, `0` errors.
11. Next-level PHPStan result:
   - Manual `--level=1` run: pass, `0` errors.
12. Tests changed and why:
   - Added `tests/Definition/RegistryStorageTest.php` to cover plain-data export, round-trip restoration, custom subclass preservation, stable wrapper identity, and reverse visibility through the underlying array.
   - Added custom Phase 3 fixtures for collection, field, display, and interface subclass round-trips.
13. Concerns deferred to Phase 4:
   - Collection-owned primary-key API and key-model redesign remain deferred.
   - Field-level PK flags, `PrimaryKeyDefinition`, and `PrimaryKeyValue` remain intact.
14. Concerns deferred to Phase 5:
   - View definitions and registry view APIs are still absent.
   - No generalized definition parent hierarchy was introduced.
