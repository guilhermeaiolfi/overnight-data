# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Version tags use MAJOR.MINOR.PATCH numbering for identification; this package does **not** treat that as a SemVer compatibility contract (see [`UPGRADE.md`](UPGRADE.md)).

## [Unreleased]

### Added

- **Session save API** — `Session::update` / `create` / `detach` / `schemaOf` with `SelectQuery::projection()` shape compilation; pending intents in `RepresentationIntentStore` apply on `sync()` (not at flush). Flat related paths via `IntentBuilder::update($path)` / `create($path)`. Docs: [`docs/orm/session-save-api.md`](docs/orm/session-save-api.md).

### Removed

- **Manual mutable projections** — `Session::projection()` Manual builder stack (`Representation\Schema\Manual`, `State\Manual`) and `Session::existing()` / `ExistingIntentStore` are removed in favor of the save API above.
- **`IntentBuilder::from()`** — unused; root collection already comes from `RepresentationSchema` (`SelectQuery::projection()` / `schemaOf()`).

### Fixed

- **Write-path field conversion** — `ConvertingCommandExecutor` converts canonical PHP command values to storage through `ConversionGateway` (`PhpRepresentation` → `StorageRepresentation`) before delegating to a backend executor. `CycleRuntimeFactory` wraps `CycleCommandExecutor` with that decorator and shares one gateway across query and command paths.
- **Insert affected rows** — `CycleCommandExecutor::insert()` reports the driver’s `execute()` row count instead of assuming `1` (Cycle `InsertQuery::run()` only returns last insert id).

### Changed

- **Fail-closed flush** — `FlushExecutor` / `Session::flush()` require `TransactionalCommandExecutorInterface` and throw `NonTransactionalFlushException` otherwise. The unsafe non-transactional flush path is removed.
- **Query object export** — `SelectQuery::to(...)` materializes rows through `map($row)->to(...)` (and `map($rows)->collection()->to(...)`) instead of a query-local hydrator. Constructor/readonly targets follow mapper rules; unknown keys are ignored.
- **Adoption simplify** — engine entry is `attach()` (Session keeps only `adopt()` for storing a ready `RepresentationState`; `RepresentationStateAdoptionTrait` removed). Removed `Session::adoptRecord()` and private `adoptGraph()`; graph sync calls `attach()` directly. `Session::identify()` builds clean baselines via `RepresentationReader::baselineValues()` then `adopt()` — not through the adoption engine. `QueryRepresentationIdentityColumns` folded into `QuerySourceIdentities`. `RepresentationAdoptionContext` only carries schema, policy, and optional identities / sourceRow / intent.
- **Writable query export bridge** — `SelectQuery::writable()` takes `WritableResultHandler` (implemented by `Session`). Compile/track live in `WritableQueryResultTracker`; Query no longer imports ORM types.
- **SelectQuery fetch path** — `fetchAll()` / `fetchOne()` / `iterate()` resolve an executor once via `getLoadRuntime()` → `LoadRuntime` (empty-relation fast path inside it).
- **Graph adoption intent** — untracked roots with a complete primary key are no longer adopted as clean/existing by default. Roots and related objects both default to `NEW` unless marked with `Session::update($object)` (or attached via `identify()` / query tracking).
- **`SelectQuery::select(RelationRef)`** — relation refs are accepted for nested loading. Bare `$u->posts` is equivalent to `$u->posts->load()` (all visible fields); already-configured refs keep their options. Relation-only `select()` keeps default root fields. Foreign-query refs raise `RelationSelectionException::foreignQueryRelation()`.
- **Separate-query parent-key chunking** — built-in loaders run separate-query continuations in batches of 100 parent keys (`AbstractLoader::executeSeparateByReferences()`), matching Doctrine eager `IN` batching. Parent-key filters use tagged `ConditionList` (`ConditionTag::CORRELATION`), not user `where()`. Custom `AbstractLoader` subclasses may override `separateQueryBatchSize()`.
- **Tagged query conditions** — `SelectQuery` stores WHERE predicates in `ConditionList` with tags (`USER`, `CORRELATION`, reserved `SCOPE` / `INTERNAL`), parallel to selection tags.
- Quickstart smoke coverage for nested `posts` relation loading.
- Relation-loading docs for separate-query parent-batch / `IN` correlation limits.

### Documentation

- Clarified docs honesty: docs describe current behavior, not a support or stable-API contract; aligned README / docs index with [`UPGRADE.md`](UPGRADE.md).
- Replaced leftover `$database` / “Database facade” wording with `DataRuntime` / `CycleRuntimeFactory`.
- Documented `SelectQuery::select(RelationRef)` for nested relation loading alongside root scalars.
- Clarified that collection `entity`/`repository`/`mapper`/`scope` and relation `cascade`/`load` are interoperability metadata for external Cycle schema bridges; ON\Data Session persistence does not interpret them.
- Rewrote [`UPGRADE.md`](UPGRADE.md) to drop the broad 1.x compatibility promise; upgrades are deliberate and may break call sites.
- Documented `x()->rawSql()` trust boundary: SQL string is trusted application code; bind values only via `?` parameters.
- Noted that `Mapping`’s default `ConversionGateway` is process-wide ambient state (isolation guidance for long-lived workers).
- Documented fail-closed flush: `TransactionalCommandExecutorInterface` is required; non-transactional flush is rejected.

## [1.1.1] - 2026-07-10

### Added

- **LIKE / NOT LIKE conditions** — `ExpressionFactory::like()`, `notLike()`, `contains()`, `notContains()`, `startsWith()`, `endsWith()` condition builders; `ComparisonOperator::LIKE` and `NOT_LIKE` cases; matching fluent shorthands on all value expressions (`$field->like(...)`, `$field->contains(...)`, etc.); `CycleQueryTranslator` translates both operators to SQL `LIKE` / `NOT LIKE`.
- **AVG / MIN / MAX aggregates** — `AggregateFunction::AVG`, `MIN`, `MAX` cases; `ExpressionFactory::avg()`, `min()`, `max()` following the same validation as `sum()` (no aliased operands, no nested aggregates); fluent shorthands on all aggregateable expressions (`$field->avg()`, `$field->min()`, `$field->max()`); `CycleQueryTranslator` translates to `AVG(...)`, `MIN(...)`, `MAX(...)`.

## [1.1.0] - 2026-07-10

ORM representation, projection, and database-adapter cleanup on top of the 1.0 foundation. Definition and mapper public APIs used by Overnight remain compatible. Deep ORM representation/session internals were reorganized; see notes below if you depended on those types directly.

### Added

- **Manual mutable projections** — `Session::projection($object)->from(...)->properties(...)->end()` for application-created or manually extended objects, with smoke coverage and docs.
- **Representation schema model** — structure-only `RepresentationSchema` / field & relation schemas, shared shape assembly, `ProjectionSource`, identity planning, and runtime attachment via `RepresentationState` items.
- **Database adapter boundary** — Cycle-backed runtime under `ON\Data\Database\Cycle\`, `DataRuntime`, and `CycleRuntimeFactory`; ADR documenting the adapter split.
- **Existing-intent graph sync** — explicit adoption/attachment intent for graph sync, with focused tests.
- **RelationCardinality** — shared cardinality helper replacing representation-specific cardinality types.
- Smoke tests mirroring quickstart, persistence, and writable `stdClass` projection examples.

### Changed

- Queries without an explicit `->select()` are treated as `->select($root->all())`.
- `flush()` defaults to transaction-based execution.
- Relation state storage unified in the session; Linker collapsed into Factory.
- Representation vocabulary cleanup: binding → schema, store/state factory boundaries, adoption → attachment where applicable.
- Record state types moved under `ON\Data\ORM\Record\`.
- Sync types moved under `ON\Data\ORM\Representation\Sync\`.
- Projection compilation reworked around shared schema compilers (query + manual) instead of parallel binding compilers.

### Fixed

- Preserve internal mutable-projection identity columns in Cycle query results so flat related-field export can adopt and flush updates against a real database executor.
- Incomplete ORM/session/persistence tests filled out; flush failure, retry/recovery, and transaction-safety coverage expanded.

### Documentation

- Docs for writable SelectQuery projections and manual writable projections.
- Representation schema docs replace the older representation-binding guide.
- Clarified v1.0 wording in foundation docs; README formatting fix.
- Architecture decision record for the database adapter boundary.

### Notes for upgraders

- If you only use definitions, mapping, and basic query execution (typical Overnight integration), upgrade to `^1.1` and run your suite.
- If you imported removed/renamed ORM types (`RepresentationBinding*`, old `ORM\State\Representation*`, `SelectQueryBindingCompiler`, `GraphAdopter`, top-level `Database\Database` helpers, etc.), switch to the new schema/state/sync namespaces documented under `docs/orm/`.

## [1.0.0] - 2026-07-05

First stable public release of `guilhermeaiolfi/overnight-data`.

### Added

- **Definitions** — canonical `Registry` storage, collection/view wrappers, typed fields and relations, and extension points for custom definition nodes.
- **Conversion & mapping** — field types, representations, codecs, `ConversionGateway`, and recursive mapping for arrays, `stdClass`, and public-property DTOs through `map(...)`.
- **Query model** — database-independent `SelectQuery` with field/relation refs, selections, aliases, expressions, aggregates, subqueries, joins, conditions, grouping, ordering, and pagination.
- **Bound execution** — neutral `Database` facade, `ConnectionConfig`, and Cycle-backed `QueryExecutorInterface` integration.
- **Relation loading** — structured relation selection with loader-owned join or separate-query execution for built-in `BelongsTo`, `HasOne`, `HasMany`, `FirstOfMany`, and `M2M` relations.
- **Query result export** — array results by default; read-only `stdClass` and public-property class export; writable `stdClass` export with flat projection provenance through `Session`.
- **ORM persistence** — `RecordState`-backed scalar insert/update/delete planning, representation sync, relation persistence planning, `FlushExecutor` / `Session` orchestration, affected-row validation, and simple auto-increment primary-key merge after inserts.

### Documentation

- Public docs under `docs/` for definitions, mapper runtime, query model, bound execution, relation loading, and ORM foundations.
- [`docs/quickstart.md`](docs/quickstart.md) walkthrough for a first end-to-end project.
- [`UPGRADE.md`](UPGRADE.md) backward-compatibility policy for the 1.x line.

### Notes

- Requires PHP `>=8.3`.
- Runtime database integration currently ships through Cycle Database.
- See README **Current Limitations** for intentional non-goals such as lazy loading, repositories, and full cascade policy.

[Unreleased]: https://github.com/guilhermeaiolfi/overnight-data/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/guilhermeaiolfi/overnight-data/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/guilhermeaiolfi/overnight-data/releases/tag/v1.1.0
[1.0.0]: https://github.com/guilhermeaiolfi/overnight-data/releases/tag/v1.0.0
