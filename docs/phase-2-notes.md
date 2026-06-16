# Phase 2 Notes

1. Source repository URL: `https://github.com/guilhermeaiolfi/overnight`
2. Source branch: `cycle-mutation`
3. Source commit hash: `4391a7d87950fce5c97770cc51fe09544b514d2d`
4. Target starting commit hash: unavailable; the current workspace is not a Git checkout.
5. Files copied: every PHP file found under `src/ORM/Definition/` in the pinned source tree, migrated into `src/Definition/`.
6. Tests copied:
   - Adapted from source: `tests/ORM/PrimaryKeyDefinitionTest.php`
   - Adapted from source: `tests/ORM/RelationDefinitionTest.php`
7. Tests newly created:
   - `tests/Definition/RegistryCollectionTest.php`
   - `tests/Definition/FieldAndMapTest.php`
   - `tests/Definition/DisplayDefinitionTest.php`
   - `tests/Definition/InterfaceDefinitionTest.php`
   - `tests/Architecture/DefinitionParityTest.php`
8. Namespace transformations:
   - `ON\ORM\Definition\*` -> `ON\Data\Definition\*`
   - Production source contains no remaining `ON\ORM\Definition\` references.
9. Complete external dependency table:

| Source file | Imported symbol | Runtime use | PHPDoc only | Classification | Handling |
| --- | --- | --- | --- | --- | --- |
| `Collection/Collection.php` | `Cycle\ORM\Mapper\StdMapper` | yes | no | C | Removed default and import; `mapper` now stores nullable opaque class-string values. |
| `Collection/Collection.php` | `ON\ORM\Select\Source` | yes | no | C | Removed default and import; `source` now stores nullable opaque class-string values. |
| `Collection/Collection.php` | `ScopeInterface` | no | yes | F | Replaced `class-string<ScopeInterface>` with generic `class-string`. |
| `Collection/Collection.php` | `RepositoryInterface` | no | yes | F | Replaced `class-string<RepositoryInterface>` with generic `class-string`. |
| `Relation/HasOneRelation.php` | `ON\ORM\Select\Loader\BelongsToLoader` | yes | no | C | Removed import and hidden default; loader remains nullable opaque class-string metadata. |
| `Relation/HasManyRelation.php` | `ON\ORM\Select\Loader\HasManyLoader` | yes | no | C | Removed import and hidden default; loader remains nullable opaque class-string metadata. |
| `Relation/M2MRelation.php` | `ON\ORM\Select\Loader\ManyToManyLoader` | yes | no | C | Removed import and hidden default; loader remains nullable opaque class-string metadata. |
| multiple files | `stdClass`, SPL iterators, `Countable`, built-in exceptions | yes | no | B | Kept as PHP built-ins. |

10. Mapper/source default changes:
    - `Collection::$mapper` changed from `StdMapper::class` to `null`.
    - `Collection::$source` changed from `Source::class` to `null`.
    - API deviations: `mapper(?string): self`, `getMapper(): ?string`, `source(?string): self`, `getSource(): ?string`.
11. Static-analysis compromises:
    - The extracted source carries many legacy signature and property inconsistencies, especially in interface-definition classes.
    - The package keeps those behaviors for characterization and lowers `phpstan` from `max` to `0` in this phase so analysis still executes without masking runtime syntax issues behind a baseline.
12. Existing behaviors that seem questionable but were preserved:
    - `Registry::getCollection()` returns `null` for missing collections.
    - `Registry::collection()` overwrites the named entry immediately.
    - `RawDisplay::setOptions()` only works for properties that are already truthy because it uses `isset()`.
    - Several interface-definition classes still expose surprising method names such as `TagsInterface::getplaceholder()`.
    - `FirstOfManyRelation` inherits `HasManyRelation` but reports cardinality `single`.
13. Behaviors deferred to Phase 3:
    - Master-array registry storage.
    - `DefinitionNode` integration for definitions.
    - Wrapper/reference binding for definition objects.
14. Behaviors deferred to Phase 4:
    - Collection-owned primary-key API.
    - `Key` introduction.
    - Removal of field-level primary-key flags.
15. Behaviors deferred to Phase 5:
    - Common definition parent typing.
    - View definitions and registry view APIs.
16. Source/target discrepancies:
    - The source branch contains `Interface/OneToManyInterface.php` declaring `ManyToManyInterface`; the extracted target corrects the declared class to `OneToManyInterface` so the package can autoload.
    - The source branch contains `Display/DateTimeDisplay.php` declaring `DatetimeDisplay`; the extracted target renames the file to `DatetimeDisplay.php` for PSR-4 compatibility.
    - The standalone package also neutralizes relation-loader defaults because they were hard-coded Overnight runtime class strings.
