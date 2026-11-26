# Upgrade Notes

`guilhermeaiolfi/overnight-data` is still evolving. This file is **not** a compatibility guarantee.

## Definition and runtime changes after `b27b9e9`

- Looking up a restored collection initializes missing or non-array `fields` and `relations` slots to empty arrays. Later additions to these collections persist in registry exports.
- A single-key `belongsTo(...)->innerKey(...)->outerKey(...)->end()` creates a missing local field using the referenced field's type and the relation's nullability. An explicitly declared field keeps its settings. If its type differs, correct the declaration using the field names and types in the exception.
- The mapper's concrete error types can now also be caught as `MappingException`.
- Relation-state methods accept DTOs and `RecordState` instances directly.
- Rename imports and instantiations of `ConvertingCommandExecutor` to `CommandExecutor`. Pass any custom `CommandValueResolver` to `CommandExecutor` or `SqlRuntimeFactory`. Direct callers of `SqlCommandExecutor` must supply storage values and can pass a custom dialect as the second argument.
- Create one `RepresentationReader` per operation and bind DTOs with `withObject()` before reading paths or relations. Use `PathReadResult::getValue()` to get a present value. It throws `SyncException` for a missing or invalid path.
- To customize mapping in low-level ORM helpers, pass the gateway to synchronizer and adoption constructors, a fresh reader to `RepresentationIdentifier::attach()`, or a reader factory to `QueryResultHydrator`. `Session` handles this wiring.
- Use `Session::prepare()` and `Session::track()` for direct writable-query tracking. `QueryRepresentationPlan::getFetchSchema()` returns the prepared schema.
- An update whose schema contains only relations now uses the supplied owner identity. Previously it could schedule an insert for a new owner.

## What documentation means

Docs describe **how the code works today**, so you can use and debug it.

They are **not**:

- a support contract
- a stability or “safe to depend on” list
- a promise that examples or type names will stay the same

Anything under `ON\Data\` may move, rename, or change behavior. `@internal` markers, if present, are optional hints only; their absence does not mean a type is stable.

Typical integration paths people start from today: `SqlRuntimeFactory` / `DataRuntime`, `SelectQuery`, and `Session`. That is convenience for orientation, **not** a supported public surface.

## Expectation

There is no promise that application code will keep working across releases without changes.

- Types under `ON\Data\` may move, rename, or change behavior.
- Documented examples may change when the code changes.
- Version tags may include breaking changes when correcting design or closing gaps — including what look like minor or patch bumps.
- Pin an exact version or a very narrow constraint and treat upgrades as deliberate work.

```bash
composer require guilhermeaiolfi/overnight-data:1.1.1
```

## Version tags

Git tags use the `vMAJOR.MINOR.PATCH` form (for example `v1.1.1`) so releases are identifiable. The numbers communicate rough scope of change when useful; they are **not** a SemVer contract for this package today.

## How to upgrade

1. Read [`CHANGELOG.md`](CHANGELOG.md) for the releases you are crossing.
2. Diff against your pin and update call sites.
3. Run your test suite and static analysis after upgrading.

### Database runtime

The built-in Cycle Database adapter has been replaced by direct SQL compilation and PDO. Replace imports from `ON\Data\Database\Cycle` with `ON\Data\Database\SqlRuntimeFactory` and `ON\Data\Database\ConnectionConfig`. Construct the config from a PDO DSN with optional credentials, PDO options, and a table prefix.

```php
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\SqlRuntimeFactory;

$factory = new SqlRuntimeFactory();
$runtime = $factory->connect(new ConnectionConfig('sqlite:/path/to/app.sqlite'));
// If you already have a configured PDO:
$runtime = $factory->create($pdo);
```

For PostgreSQL schema selection, use schema-qualified collection tables or configure `search_path` on the PDO connection before calling `create()`. Custom query functions can still call `platform()->nativeDriver()`. The PDO adapter now returns the PDO handle there.

SQLite 3.35+ connections use `INSERT ... RETURNING` by default. Applications that previously read values after an AFTER INSERT trigger can set `insertReturning: false` in `ConnectionConfig` to keep the follow-up read by key.

If your application still uses Cycle for its own database access, retain its Composer dependency and supply an ON\Data `ConnectionInterface` implementation to reuse that connection for compiled SQL. `CycleCollectionPlugin` still provides definition metadata for external Cycle ORM tools.

### Other recent breaks

- Custom relation loaders no longer implement `register()` / `initNode()` or construct `ON\Data\Query\Result\Parser\*` nodes (the `Result\Parser` subsystem was removed). In `load()`, register a row collector via `$runtime->collectWith($branch, ...)` (using `KeyPairCollector` or any `RowCollectorInterface` / closure) and deferred work via `$runtime->continueWith($branch, $this->loadData(...))`. In `loadData()`, call `$runtime->execute($branch, $query, $collector)` (or `$runtime->ingest($branch, $rows, $collector)` for non-SQL sources).
- `ON\Data\ORM\Persistence\RecordFlusher` was removed. Normal persistence uses `Session::flush()`. For low-level callers, replace `flush($records)` with `FlushScheduler::run($records)->getCommandResults()` and `flushDeferred($records)` with `FlushScheduler::run($records, [], true)`. The scheduler does not add synchronization, relation planning, or transaction handling.
- `Session::flush()` now applies staged `update()` / `create()` intents, including schema overlays, without requiring an explicit `sync()` first. Calls that previously staged writes and then flushed without writing now persist them. `sync()` without an object also consumes staged intents. Unresolved intents fail instead of being ignored.
- `Session::flush()` / `FlushExecutor` require `TransactionalCommandExecutorInterface`. Plain `CommandExecutorInterface` implementations are rejected with `NonTransactionalFlushException`.
- Field and relation `interface()` / `display()` moved to `UiDefinitionPlugin` via `extend(UiDefinitionPlugin::class)` / `getPlugin()`.
- Collection `entity()` / `repository()` / `mapper()` / `scope()` moved to `CycleCollectionPlugin`.
- Field `comment()` is gone; use `description()`.

## Reporting issues

If a release breaks something that the docs still describe as current behavior, open a GitHub issue with the versions you upgraded from and to. That helps fix docs or code; it is not a claim that the old behavior was guaranteed.

### ORM tracking internals

`RecordHistory`, `RecordState::getRevision()`, `RecordState::getHistory()`, `RepresentationFieldStateItem`, and `RepresentationRelationStateItem` have been removed. Code that builds state for `Session::adopt()` should call `RepresentationState::fromRecords($schema, $recordsBySourceKey)`. Use the empty source key for the root record and schema source keys for flat related records. Use `hasBaseline()` and `getBaseline()` to inspect a field baseline by its representation path.

`RepresentationAdoptionEngine` handles staged creates and updates. `QueryResultHydrator` handles writable query results. It shares record binding with `Session::identify()` so both paths recognize an existing record by the same key.
