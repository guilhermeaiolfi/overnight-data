# ORM Persistence

The current ORM persistence layer writes scalar record fields and plans configured relation changes. It is intentionally small: representations synchronize into `RecordState` and relation state, dirty/new/removed records become neutral commands, configured relation planners can mutate scalar record state or add commands, and an adapter executes those commands against a backend.

It is not a full entity manager, unit of work, automatic relation cascade writer, or public transaction coordinator. Command executors used with `Session::flush()` must implement `TransactionalCommandExecutorInterface`; flush always runs inside that executor-backed transaction and refuses executors that do not provide one.

## Flow

```text
ScalarRepresentationSynchronizer
  object scalar fields -> RecordState scalar values

RelationRepresentationSynchronizer
  object relation paths from RepresentationSchema -> ToManyRelationState / ToOneRelationState runtime state

RelationPersistencePlanner
  ToManyRelationState / ToOneRelationState changes -> RecordState mutations and/or extra persistence commands

FlushScheduler
  RecordState lifecycle/dirty state -> InsertCommand / UpdateCommand / DeleteCommand

CommandExecutorInterface
  executes neutral commands
```

`FlushExecutor` coordinates the pipeline as representation sync, relation persistence planning, then record flushing. `Session` owns the in-memory runtime stores used by that flow: weak `RepresentationStateStore`, strong `RecordStateStore`, strong `RelationStateStore` for both to-many and to-one state.

Normal callers use `Session::flush()`. `FlushScheduler::run()` is the lower-level command scheduler: it plans record commands, resolves dependencies, executes commands, and finalizes record state. It does not synchronize representations, plan relation changes, or open a transaction. `run(..., deferFinalizers: true)` returns a `DeferredFlushResult` so `FlushExecutor` can finalize record state only after commit.

The necessary write steps (and the matching read steps) are in [`../read-write.md`](../read-write.md). This document is the current how-it-works for scalar commands, relation planners, and SQL execution.

## State And Sync

During inbound adoption, identification, writable query hydration, and sync, the session reads scalar and relation paths with `map($object, gateway: $gateway)->shallow()->to([])`. It reuses each visited object's mapped properties within one pass. Arrays, iterables, and nested objects pass through as written, so the session tracks the exact child instances exposed by the mapper. Schema paths refer to the names exposed by the mapper, and a required relation omitted from its output produces a missing-path error. The default object mapper treats properties marked `#[Hidden]` as missing. PATCH updates omit missing fields and apply explicit `null` values.

`new Session($executor, gateway: $gateway)` uses that `ConversionGateway` for representation reads in the session's default components. Sessions without an explicit gateway use `Mapping::getDefaultGateway()` for those reads. Custom mappers that override the default object mapper can be placed first with `$gateway->getMapperManager()->prepend(MyMapper::class)`.

`$runtime->session()` uses the runtime's command executor and the same gateway as `$runtime->query(...)`. Pass a `FlushHistory` to `$runtime->session($history)` to journal writes. `SqlRuntimeFactory::create($pdo, $gateway)` and `connect($config, $gateway)` share the supplied gateway across SQL value conversion, query object export, and sessions created by the runtime. `$runtime->getGateway()` returns that instance.

Expose live relation targets through public properties with the default object mapper, or through a custom mapper. The session uses the original PHP instances to resolve their tracked state.

A writable query can hydrate a custom entity through a writer registered on the runtime gateway. Prepend the writer and its matching mapper when they need to take priority over the defaults. The mapper must expose the scalar and relation paths selected by the query so the session can track the entity and flush changes.

`RecordState` is the persistence source of truth. It stores canonical PHP values keyed by collection field name, the original clean snapshot, lifecycle state, and identity through `ON\Data\Key`.

Writable query hydration and `Session::identify()` capture explicit `null` field values in clean snapshots. A field that was omitted stays absent from the snapshot.

Representations are user-facing objects or values. A representation object can drift from its record state until synchronization happens. `RepresentationStateStore` associates the object with `RepresentationState` through weak object keys, so the session does not keep otherwise-unused representation objects alive. `ScalarRepresentationSynchronizer` reads field schemas only and applies planned representation field updates into `RecordState` while preserving the conflict rules from the ORM foundation: a representation edit conflicts when its value and the current record value differ from each other and from the representation baseline.

`Session::sync(?object $representation = null, ?RepresentationSchema $schema = null)` is the explicit graph entry point. With no argument it first applies all pending update/create intents, then syncs tracked representations. Flush applies the same pending intents before starting its write transaction. With an object it walks that object's explicit `RepresentationRelationSchema` graph, syncs scalar values into `RecordState`, and syncs relation values into `ToManyRelationState` / `ToOneRelationState` runtime state. It returns `SyncResult`, containing scalar sync plans and touched relation changes. Scalar plans are validated as one batch: differing values targeting the same concrete record field are rejected before any plan is applied. It does not plan relation persistence, flush records, execute commands, mark records clean, or clear relation changes.

For an untracked root object, `sync($object, $schema)` requires a root `RepresentationSchema` that targets exactly one root collection through its field schemas and/or relation owner schemas. This entry point is for entity-shaped object schemas. A schema with no resolvable collection, or a mixed/projection schema spanning multiple collections, cannot create a root `RecordState` and raises `StateException`. When the schema is valid, the root is tracked from that schema, then the method follows only explicit relation schemas. For `MANY` relation schemas, `null` means an empty collection and a non-null value must be iterable objects. For `ONE` relation schemas, `null` means no target and a non-null value must be an object. Each discovered untracked related object is tracked with that relation schema's `getRelatedSchema()`, and the walk continues recursively while guarding object identity cycles.

Primary-key presence is data, not lifecycle intent. `sync()` discovers schema and collection context from tracked roots and relation schemas. Untracked objects discovered through that graph—**roots and related objects alike**—default to `NEW`, even when they contain a complete application-assigned primary key. Missing, complete, and composite primary keys are all accepted for new objects as long as normal insert rules allow them. Use `Session::update($object)` when a real object should be treated as an existing row during graph sync; the marker attaches update intent to the PHP object only and does not require a collection, schema, or key at marker time. When that object is later adopted by `sync()`, the schema (or fluent `->identity()`) supplies identity, the record starts from a key-only clean baseline, and present DTO fields are applied as a PATCH (dirty updates). Use `Session::identify($collection, $key)` for key-only existing references with no field writes. Upsert is not implicit and is not implemented here: duplicate application-assigned keys on new objects are planned as inserts and should fail at the database constraint level rather than being silently converted to updates.

`Session::update(object $representation, ?RepresentationSchema $schema = null): IntentBuilder` marks a representation object for update before graph sync discovers it. Intent is stored in `RepresentationIntentStore` on the PHP object. Fluent `IntentBuilder::identity()` supplies the root key when the PK is not readable from the DTO/map; if both identity and a readable PK disagree, adoption fails. When the object is later adopted as a root or through a relation schema, present fields are written onto the existing `RecordState` (not a silent clean snapshot of the full DTO). See [`session-save-api.md`](./session-save-api.md).

`Session::identify(CollectionInterface $collection, Key|array $key, ?object $representation = null, ?RepresentationSchema $schema = null): object` explicitly attaches a representation to an existing row known by key without querying. Without a representation it creates a key-only `stdClass` using same-name primary-key paths. Without a schema it creates a minimal key-only schema. The resulting record is clean, not new or dirty, and can be reused for deletion or relation unlinking.

For an already-tracked root object, `sync($object)` uses its existing tracked schema and can bring newly attached related plain objects into the session through explicit relation schemas. Passing an extra schema for an already-tracked object is currently unnecessary; the tracked schema is the source of truth. Query/projection/mixed schemas remain valid provenance for already-tracked or query-created representations because they already have concrete tracked state; they are not used to silently create a new root record.

For a new plain object graph:

```php
$session->sync($user, $userSchema);
$session->flush();
```

For an existing plain object that was not loaded through a query or `identify()`:

```php
$session->update($user);
$session->sync($user, $userSchema);
$session->flush();
```

For an already-tracked or loaded object graph:

```php
$session->sync($user);
$session->flush();
```

`Session::remove($target)` marks one record for deletion. Passing a `RecordState` tracks it if needed and marks that state removed. Passing an object requires that exact object to already be tracked in `RepresentationStateStore`; `remove()` does not adopt untracked objects and does not accept a schema override. The tracked representation schema must resolve to exactly one concrete root `RecordState`. Projection or mixed schemas that point at multiple records are rejected because they are not safely removable as a single record.

Removal is record deletion only. It does not cascade to related records, perform orphan removal, or infer child deletion from object graph shape. Relation unlinking still goes through explicit `ToManyRelationState` / `ToOneRelationState` mutations and the configured relation persistence planners. For example, `Session::remove($post)` deletes the represented post row, while `$userPosts->remove($post)` unlinks that child from the relation. For many-to-many relations, removing an identified key-only child from an unloaded `ToManyRelationState` deletes the through row without marking the collection fully loaded.

`Session::flush()` still calls strict representation sync automatically before relation persistence planning and record flushing. That pre-flush sync does not adopt new untracked related objects. Calling `sync($object)` before `flush()` is the explicit step that admits a changed object graph into the session.

For long-lived workers, prefer one `Session` per request/job. When intentionally reusing a session, call `Session::clear()` between jobs to drop all four runtime stores. If sessions are discarded normally, an extra clear step is unnecessary.

`RelationRepresentationSynchronizer` reads relation schemas only. It projects current representation relation values into `ToManyRelationState` and `ToOneRelationState` runtime state; it does not write scalar fields, execute commands, or adopt child objects by itself. In the strict no-argument sync and pre-flush sync paths, any object discovered through a relation schema must already be tracked/adopted; an untracked related object raises `SyncException` with the relation path. This strict behavior is intentional to avoid hidden persistence from `flush()`.

For `MANY` relation schemas, the representation path must contain an iterable of objects or `null`. The synchronizer creates or reuses a `ToManyRelationState` for the concrete owner record and relation name. A `null` value is treated as an empty current item list. Before projecting items, it applies `RepresentationRelationSchema` load knowledge:

- `Full` — mark the collection fully loaded (unqualified query relation load: no relation `where` / `limit` / `offset`)
- `Partial` — mark partially loaded (filtered or sliced query relation load)
- `Unknown` — leave load state unchanged (inbound graph / identify / hand-built schemas)

When Full or Partial knowledge is applied to a previously unloaded collection, later syncs can remove absent members (Full) or only add newly present items (Partial). Query hydrate does not go through that path: it constructs `ToManyRelationState::full()` or `::partial()` (or `ToOneRelationState`) with the walked children already as baseline. Inbound `sync()` onto an unloaded collection is a diff: present items are pending adds, not a loaded snapshot.

For `ONE` relation schemas, the representation path must contain an object or `null`. The synchronizer creates or reuses a `ToOneRelationState` for the concrete owner record and relation name, then sets the current target. `ToOneRelationState` keeps both baseline and current target object identity so the planner can distinguish unchanged, replaced, and cleared references.

`RelationPersistencePlanner` consumes changed `ToManyRelationState` and `ToOneRelationState` instances. It resolves each relation's configured `RelationPersistencePlannerInterface`, lets that planner mutate scalar `RecordState` values and/or add commands, and returns the planned relation changes plus commands for `FlushExecutor` to finish.

The built-in relation planners are:

- `ManyToManyPersistencePlanner`: consumes `ToManyRelationState` changes for `M2MRelation`, inserting or deleting rows in the through collection.
- `HasManyPersistencePlanner`: consumes `ToManyRelationState` changes for `HasManyRelation`, propagating owner key values into added child records and nulling child outer keys for nullable removals.
- `BelongsToPersistencePlanner`: consumes `ToOneRelationState` changes for `BelongsToRelation`, propagating target key values into the owner record or nulling owner inner keys for nullable clears.
- `HasOnePersistencePlanner`: consumes `ToOneRelationState` changes for `HasOneRelation`, propagating owner key values into the current target record and, when replacing or clearing, nulling the previous target outer keys when the relation is nullable.

For a single-key `belongsTo`, the owner holds the foreign key in its inner field and the relation's `end()` infers that field's type from the target outer field. A `hasOne` leaves the owner field definition intact and writes the foreign key on the target.

For scalar foreign-key relation shapes, the has-many, belongs-to, and has-one planners may write a `ValueRef` when the source key is not concrete yet. If the source key is already concrete, `RecordState::setValue()` collapses that reference immediately and the target record stores the concrete value. Relation planners require involved target objects to already be tracked so they can resolve each object to a `RecordState`. They do not adopt new objects, load missing relations, or orchestrate transactions.

## Internal Value References

`ValueRef` is an internal ORM state value used to represent a temporary dependency on another `RecordState` field. A `RecordState` may temporarily hold a `ValueRef` in its current values and dirty values. When the referenced field later contains a concrete non-null value, `RecordState::resolveValueRefs()` collapses the reference into that concrete value.

Neutral ORM persistence commands may also temporarily carry `ValueRef` values while they remain inside the flush pipeline. `CommandPlanner` and relation planners can build `InsertCommand`, `UpdateCommand`, and `DeleteCommand` instances that still contain unresolved references. `CommandValueResolver` collapses resolved command references in place before execution and rejects unresolved references at the adapter boundary.

`FlushScheduler` flushes records in waves. Before each planning attempt it resolves any now-ready references and only plans records whose command-relevant values are concrete. This allows generated-key dependencies such as a new user with a new has-many post, a new belongs-to target with a new owner, or a new has-one owner with a new target to flush as parent/source insert first, generated value merge second, dependent record command third.

Many-to-many through-row commands use `ValueRef` for mapped owner and target key fields. Within each scheduling wave, ready record writes execute before extra relation commands. Generated owner and target keys can therefore be merged into their records before `CommandValueResolver` resolves a through-row command for `CommandExecutorInterface`.

## Commands

Persistence commands are database-neutral:

- `InsertCommand`
- `UpdateCommand`
- `DeleteCommand`

Each command carries the owning `CollectionInterface` plus canonical field-keyed values. Commands do not carry physical table names, column names, SQL strings, database connections, repositories, or registry lookups.

`CommandPlanner` is stateless. It plans:

- new records as `InsertCommand`
- dirty records with a key as `UpdateCommand`
- removed records with a key as `DeleteCommand`
- clean records as no command

Composite identity remains first-class. Update and delete identities are keyed by primary-key field name in definition order.

`FlushScheduler` preserves command result order by actual execution wave. Clean records do not block a flush, and removed keyless records are discarded without a command. Removed keyed records only require concrete delete identity values; unresolved non-identity values do not block deletion.

## Execution

`CommandExecutorInterface` is the adapter boundary:

```php
public function execute(CommandInterface $command): CommandResult;
```

`ConvertingCommandExecutor` is the adapter-agnostic write boundary. It projects command field values from canonical PHP to storage through `ConversionGateway` (`PhpRepresentation` → `StorageRepresentation`), using the same conversion authority as query literal binding and result mapping. It does not mutate the original command objects.

The built-in `SqlCommandExecutor` runs parameterized insert, update, and delete statements. `SqlCommandCompiler` resolves the physical SQL names.

- The table comes from `CollectionInterface::getTable()` with the connection's table prefix.
- Column names come from the field definitions. An unknown command field raises `InvalidCommandException`.

Command and `RecordState` values remain canonical PHP until `ConvertingCommandExecutor`. The SQL executor receives storage values and calls `CommandValueResolver::assertReady()` before compilation. An unresolved `ValueRef` raises `InvalidCommandException`. The executor delegates flush transactions to `ConnectionInterface`.

Custom relation planners that implement `StatelessRelationPersistencePlannerInterface` can be reused across flushes. Other planner implementations are instantiated per relation change.

Database executors must implement `TransactionalCommandExecutorInterface`. `FlushExecutor` runs the whole command wave inside one executor transaction and throws `NonTransactionalFlushException` when the executor does not implement that interface.

## Affected-row validation

`FlushScheduler` validates affected rows after each command executes. Insert, update, and delete commands default to expecting exactly one affected row. Commands can carry an explicit `ExpectedAffectedRows` policy when a different result is valid.

Many-to-many through-row deletes may use a zero-or-one affected-row policy because unlinking an already-absent through row is valid.

When the actual affected-row count does not match the command policy, flush raises `UnexpectedAffectedRowsException`. Failed commands leave record state unchanged for that operation.

## Generated Values

`CommandResult` can carry generated values keyed by field name. `FlushScheduler` merges those values into the inserted `RecordState`, marks the record clean, and indexes it by `ON\Data\Key` when the key becomes complete.

Field definitions declare generation with `->generator($classOrInstance, $arg = null, $when = null)`:

```php
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Field\Generator\NowGenerator;
use ON\Data\Definition\Field\Generator\UuidGenerator;
use ON\Data\Definition\Field\Generator\When;

->field('id', 'int')->generator(DatabaseGenerator::class)->end()
// sugar:
->field('id', 'int')->autoIncrement(true)->end()

->field('balance', 'int')
    ->generator(new DatabaseGenerator('user_balance_seq'))
    // or ->generator(DatabaseGenerator::class, 'user_balance_seq')
    // or ->generator(DatabaseGenerator::class, ['sequence' => 'user_balance_seq'])
    ->end()

->field('token', 'string')
    ->generator(UuidGenerator::class, null, When::INSERT)
    ->end()

->field('updatedAt', 'datetime')
    ->generator(NowGenerator::class, 'UTC', When::INSERT | When::UPDATE)
    // or ->generator(new NowGenerator('UTC'), null, When::INSERT | When::UPDATE)
    ->end()
```

- `DatabaseGenerator` — database-owned (identity / sequence / DB default). Optional `$arg` is a sequence name (string) or `['sequence' => '...']` used as schema metadata for the column default (and passed through to `lastInsertId($sequence)` on the non-RETURNING path). Executors fill `CommandResult`:
  - The insert and update readback rules below determine which values are recovered. `ConvertingCommandExecutor` converts them back to canonical PHP.
- `UuidGenerator` — PHP-owned RFC 4122 UUID v4 string (`xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`). No constructor arg.
- `NowGenerator` — PHP-owned `DateTimeImmutable('now')`. Optional `$arg` is a timezone name (e.g. `UTC`) or pass `new NowGenerator('UTC')` / `new NowGenerator(new DateTimeZone('UTC'))`.
- `PhpFieldGeneratorInterface` — PHP-owned; `CommandPlanner` runs `generate()` above adapters before INSERT/UPDATE (`When` bitmask). `$arg` may be a scalar, a list of constructor args, or a single constructor value.
- Instances are allowed when they implement `GeneratorDefinitionArgInterface` (or you pass an explicit `$arg`); they flatten into the array definition as `class` + `arg`.
- `autoIncrement(true)` is sugar for `generator(DatabaseGenerator::class, null, When::INSERT)`.

`SqlCommandExecutor` requests omitted insert columns, such as SQL defaults and generated keys. An explicit `null` in the insert payload counts as a supplied value. Update readback uses a follow-up `SELECT` for fields tagged `DatabaseGenerator` with `When::UPDATE`, so it can see changes made by triggers.

PostgreSQL, SQLite 3.35+, and MariaDB 10.5+ support `INSERT ... RETURNING`. SQL Server uses `OUTPUT INSERTED`. A returned row counts as one affected row. For SQLite inserts that report zero and return no row, the executor checks `SELECT CHANGES()` on the same connection.

MySQL and older SQLite or MariaDB use a single generated primary key from `lastInsertId($sequence)` and then `SELECT` any remaining values when a complete identity is available. An application-assigned key also works for the follow-up query. SQLite `RETURNING` exposes values from before AFTER triggers. SQL Server `OUTPUT` exposes pre-trigger values and fails on tables with enabled INSERT triggers. Set `insertReturning: false` in `ConnectionConfig` or `PdoConnection` to select the fallback on either engine. Supply a key for SQL Server inserts on this path because the driver's last identity can come from a table written by a trigger.

Generated values use field names as keys. A requested column returned as SQL `NULL` appears with a PHP `null` value. `ConvertingCommandExecutor` converts storage values before `FlushScheduler` merges them into `RecordState`.

Generated values are merged into in-memory record state as soon as the insert command succeeds so later dependent commands in the same flush can resolve concrete foreign-key values. When the flush transaction fails, `FlushExecutor` restores in-memory record snapshots so rolled-back generated values are not left exposed after failure. Relation change markers remain pending so a full retry can plan again.

## Small Example

```php
use ON\Data\Database\SqlRuntimeFactory;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Record\RecordState;

// Use a PDO connection with the app_users table already created.
/** @var PDO $pdo */

$registry = new Registry();
$users = $registry
    ->collection('users')
    ->table('app_users')
    ->primaryKey('id')
    ->field('id', 'int')->column('user_id')->autoIncrement(true)->end()
    ->field('name', 'string')->column('full_name')->end();

$runtime = (new SqlRuntimeFactory())->create($pdo);
$session = $runtime->session();

$record = RecordState::new($users, [
    'name' => 'Ada Lovelace',
]);
$session->getRecords()->add($record);

$session->flush();

$generatedId = $record->getValue('id');
```

`SqlRuntimeFactory` wires `ConvertingCommandExecutor` around `SqlCommandExecutor` automatically.

## Public Runtime

`FlushExecutor` is the low-level orchestration service for a flush cycle. It:

- synchronizes representation fields and relation values into ORM runtime state
- plans configured relation persistence changes into scalar mutations and/or commands
- flushes changed records through `FlushScheduler`
- returns `FlushResult` with sync plans and command results

`Session` is the small runtime container around tracked representations and records. It provides public entry points for explicitly syncing an object graph and flushing planned persistence work.

This is deliberately not an `EntityManager`. There is no repository API, object proxy system, lifecycle event system, generated model layer, or relation cascade writer. `sync($object)` is graph synchronization only; it is not a cascade policy, orphan-removal policy, generated-key dependency sorter, or transaction boundary. `remove($object)` only removes an already-tracked representation that maps to one concrete record.

Pending write admission happens in memory before persistence. If a transaction fails, the flush executor restores record identity, values, lifecycle, and tracked representation baselines together. Admitted writes stay tracked for retry.

`Session::flush()` always runs inside a database transaction via `TransactionalCommandExecutorInterface` (including `ConvertingCommandExecutor` wrapping `SqlCommandExecutor`). Executors that omit that interface are rejected with `NonTransactionalFlushException`. There is no separate transaction API on `Session`.

For an optional, bounded journal of committed commands and transactional undo, see [Flush history](./history.md).

## Current Limits

- Scalar insert/update/delete plus configured relation persistence planning only.
- Untracked root objects passed to `sync($object)` need an explicit root `RepresentationSchema` targeting one collection; related objects use each relation schema's `getRelatedSchema()`.
- Untracked objects adopted by `sync()` become new records by default. Mark `Session::update($object)` (with a complete readable primary key through the schema, or `->identity()`) to PATCH present fields onto an existing row, or use `identify()` for key-only existing references with no field writes.
- `identify()` is the public key-only existing-record primitive. It does not query or load records from the database.
- `sync()` accepts object roots only; array input is not supported yet.
- No automatic relation cascade writes.
- No automatic graph adoption from `flush()`.
- `Session::flush()` requires `TransactionalCommandExecutorInterface` and always uses executor-backed transactions; there is no separate transaction API on `Session`.
- Non-transactional flush is not supported (`NonTransactionalFlushException`).
- No optimistic locking or stale-row revision conflict handling beyond representation sync baseline checks.
- No lazy loading.
- No repositories, `EntityManager`, `UnitOfWork`, lifecycle events, proxies, or generated model layer.
- No `Session::refresh()` of an existing row; omitted insert columns and `DatabaseGenerator` update columns are recovered on the write path via `RETURNING` or a follow-up select as documented above.
- No batch command execution.
- No public SQL command API.
- With the default object writer, writable export supports `stdClass` and mutable public-property classes. Readonly classes and classes with readonly public properties require a custom writer and a mapper that exposes the tracked paths.
- Writable iteration is not supported yet.
- Flat projection provenance is for writable query export.
