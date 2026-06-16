# Phase 4 Notes

1. Starting state
   - Phase 3 ending commit found in `git log`: `a95f62e`
   - Phase 4 starting commit: uncommitted workspace; no clean baseline commit was created because the working tree already contained user-owned doc changes in `docs/1-definitions/`
   - Phase 4 ending commit: pending; this implementation is left uncommitted
   - Configured PHPStan level: `0` in `phpstan.neon.dist`
   - Manual PHPStan verification level: `1`
   - Baseline PHPUnit count before Phase 4 changes: `47` tests
2. Baseline quality results
   - `composer test`: pass, `47` tests / `829` assertions
   - `composer analyse`: pass
   - `vendor/bin/phpstan analyse --configuration phpstan.neon.dist --level=1`: pass
   - `composer check-style`: pass
3. Production usage inventory found before migration
   - `PrimaryKeyDefinition`: `src/Definition/Collection/Collection.php`, `src/Definition/Collection/PrimaryKeyDefinition.php`, `src/Definition/Collection/PrimaryKeyValue.php`
   - `PrimaryKeyValue`: `src/Definition/Collection/PrimaryKeyDefinition.php`, `src/Definition/Collection/PrimaryKeyValue.php`
   - `Field::primaryKey()`: via `src/Definition/Field/SchemaTrait.php`
   - `FieldInterface::primaryKey()`: `src/Definition/Field/FieldInterface.php`
   - `SchemaTrait::primaryKey()`: `src/Definition/Field/SchemaTrait.php`
   - `FieldInterface::isPrimaryKey()`: `src/Definition/Field/FieldInterface.php`, implementations in `SchemaTrait`
   - `Collection::getPrimaryKey()`: `src/Definition/Collection/Collection.php`, relation validation in `src/Definition/Relation/AbstractRelation.php`, old PK classes
   - `Collection::getPrimaryKeyFields()`: `src/Definition/Collection/Collection.php`
4. Migration normalization rules
   - Collection-level `primaryKey` is authoritative when present
   - Legacy field `pk` flags are scanned in stored field order only when collection-level `primaryKey` is absent or empty
   - Matching legacy and new formats are accepted
   - Conflicting legacy/new formats throw `ConflictingPrimaryKeyDefinitionException`
   - Canonical `Registry::all()` output removes field-level `pk` flags
5. API changes
   - Removed field-level PK configuration
   - Added collection-owned `primaryKey()` and `hasPrimaryKey()`
   - Added `Collection::getPrimaryKeyColumns()`
   - Added `Collection::getKey()` and `Collection::getKeyFromRecord()`
   - Added `ON\Data\Key`
   - Removed `PrimaryKeyDefinition` and `PrimaryKeyValue`
6. Relation changes
   - Relation PK arity validation now compares against `count($collection->getPrimaryKey())`
   - Composite relation and M2M tests were updated to use collection-owned PK metadata
7. Tests replaced
   - Removed `tests/Definition/PrimaryKeyDefinitionTest.php`
8. Tests added
   - Added `tests/Definition/PrimaryKeyAndKeyTest.php`
9. Files changed in this phase
   - Core PK model: `src/Definition/Collection/Collection.php`, `src/Definition/Collection/CollectionInterface.php`, `src/Definition/Registry.php`, `src/Key.php`
   - Exceptions: new PK exception classes under `src/Definition/Exception/`
   - Field cleanup: `src/Definition/Field/Field.php`, `src/Definition/Field/FieldInterface.php`, `src/Definition/Field/SchemaTrait.php`
   - Relations: `src/Definition/Relation/AbstractRelation.php`
   - Tests: updated PK, relation, storage, field, and architecture coverage
   - Docs: `README.md`, `docs/phase-2-public-api.md`, `docs/phase-3-storage-format.md`, `docs/phase-4-key-format.md`, `docs/phase-4-notes.md`
10. Deferred concerns
   - Views: no `ViewDefinition`, view registry APIs, or query abstractions were introduced
   - FieldType integration: no scalar normalization or URL/REST decoding was introduced; key values must already be canonical scalar values
11. Internal cleanup candidates
   - `Collection::bindDefinitionArray()` remains public only for current wrapper rebinding mechanics and should become a more clearly internalized construction hook in a later phase
   - Optional array-reference hydration constructor arguments remain infrastructure-oriented and are still public because of the current factory architecture
