# ORM Persistence

The current ORM persistence layer writes scalar record fields and plans configured relation changes. It is intentionally small: representations synchronize into `RecordState` and relation state, dirty/new/removed records become neutral commands, configured relation planners can mutate scalar record state or add commands, and an adapter executes those commands against a backend.

It is not a full entity manager, unit of work, automatic relation cascade writer, or transaction coordinator. `Session::flush()` does use executor-backed transactions when the command executor implements `TransactionalCommandExecutorInterface`.

## Flow

```text
ScalarRepresentationSynchronizer
  object scalar fields -> RecordState scalar values

RelationRepresentationSynchronizer
  object relation paths from RepresentationBinding -> ToManyRelationState / ToOneRelationState runtime state

RelationPersistencePlanner
  ToManyRelationState / ToOneRelationState changes -> RecordState mutations and/or extra persistence commands

RecordFlusher
  RecordState lifecycle/dirty state -> InsertCommand / UpdateCommand / DeleteCommand

CommandExecutorInterface
  executes neutral commands
```

`FlushExecutor` coordinates the pipeline as representation sync, relation persistence planning, then record flushing. `Session` owns the in-memory runtime stores used by that flow: weak `RepresentationStore`, strong `RecordStateStore`, strong `ToManyRelationStore`, and strong `ToOneRelationStore`.

## State And Sync

`RecordState` is the persistence source of truth. It stores canonical PHP values keyed by collection field name, the original clean snapshot, lifecycle state, and identity through `ON\Data\Key`.

Representations are user-facing objects or values. A representation object can drift from its record state until synchronization happens. `RepresentationStore` associates the object with `RepresentationState` through weak object keys, so the session does not keep otherwise-unused representation objects alive. `ScalarRepresentationSynchronizer` reads field bindings only and applies planned representation field updates into `RecordState` while preserving the conflict rules from the ORM foundation: a representation based on a stale record revision cannot silently overwrite a newer record value.

`Session::sync(?object $representation = null, ?RepresentationBinding $binding = null)` is the explicit graph entry point. With no argument it strictly syncs already-tracked representations. With an object it walks that object's explicit `RepresentationRelationBinding` graph, syncs scalar values into `RecordState`, and syncs relation values into `ToManyRelationState` / `ToOneRelationState` runtime state. It returns `SyncResult`, containing scalar sync plans and touched relation changes. It does not plan relation persistence, flush records, execute commands, mark records clean, or clear relation changes.

For an untracked root object, `sync($object, $binding)` requires a root `RepresentationBinding` that targets exactly one root collection through its field bindings and/or relation owner bindings. This entry point is for entity-shaped object bindings. A binding with no resolvable collection, or a mixed/projection binding spanning multiple collections, cannot create a root `RecordState` and raises `StateException`. When the binding is valid, the root is tracked from that binding, then the method follows only explicit relation bindings. For `MANY` relation bindings, `null` means an empty collection and a non-null value must be iterable objects. For `ONE` relation bindings, `null` means no target and a non-null value must be an object. Each discovered untracked related object is tracked with that relation binding's `getRelatedBinding()`, and the walk continues recursively while guarding object identity cycles.

Primary-key presence is data, not lifecycle intent. `sync()` discovers binding and collection context from tracked roots and relation bindings. Untracked related objects discovered through that graph default to `NEW`, even when they contain a complete application-assigned primary key. Missing, complete, and composite primary keys are all accepted for new related objects as long as normal insert rules allow them. Use `Session::existing($object)` when a real related object should be treated as an existing row during graph sync; the marker attaches intent to the PHP object only and does not require a collection, binding, or key at marker time. When that object is later discovered by `sync()`, the relation binding supplies collection context and the primary-key values are read through that binding to build the existing `RecordState` identity. Use `Session::identify($collection, $key)` for key-only existing references. Upsert is not implicit and is not implemented here: duplicate application-assigned keys on new objects are planned as inserts and should fail at the database constraint level rather than being silently converted to updates.

For an untracked root with a complete readable primary key, graph adoption still treats the root as an existing clean row. That root entry point is explicit and separate from relation discovery.

`Session::existing(object $representation): ExistingIntent` marks a representation object as existing before graph sync discovers it. The returned marker is attached to the PHP object only. When the object is later adopted through a relation binding, its readable primary-key values become the existing record identity and the record is adopted as clean, not new.

`Session::identify(CollectionInterface $collection, Key|array $key, ?object $representation = null, ?RepresentationBinding $binding = null): object` explicitly attaches a representation to an existing row known by key without querying. Without a representation it creates a key-only `stdClass` using same-name primary-key paths. Without a binding it creates a minimal key-only binding. The resulting record is clean, not new or dirty, and can be reused for deletion or relation unlinking.

For an already-tracked root object, `sync($object)` uses its existing tracked binding and can bring newly attached related plain objects into the session through explicit relation bindings. Passing an extra binding for an already-tracked object is currently unnecessary; the tracked binding is the source of truth. Query/projection/mixed bindings remain valid provenance for already-tracked or query-created representations because they already have concrete tracked state; they are not used to silently create a new root record.

For a new plain object graph:

```php
$session->sync($user, $userBinding);
$session->flush();
```

For an already-tracked or loaded object graph:

```php
$session->sync($user);
$session->flush();
```

`Session::remove($target)` marks one record for deletion. Passing a `RecordState` tracks it if needed and marks that state removed. Passing an object requires that exact object to already be tracked in `RepresentationStore`; `remove()` does not adopt untracked objects and does not accept a binding override. The tracked representation binding must resolve to exactly one concrete root `RecordState`. Projection or mixed bindings that point at multiple records are rejected because they are not safely removable as a single record.

Removal is record deletion only. It does not cascade to related records, perform orphan removal, or infer child deletion from object graph shape. Relation unlinking still goes through explicit `ToManyRelationState` / `ToOneRelationState` mutations and the configured relation persistence planners. For example, `Session::remove($post)` deletes the represented post row, while `$userPosts->remove($post)` unlinks that child from the relation. For many-to-many relations, removing an identified key-only child from an unloaded `ToManyRelationState` deletes the through row without marking the collection fully loaded.

`Session::flush()` still calls strict representation sync automatically before relation persistence planning and record flushing. That pre-flush sync does not adopt new untracked related objects. Calling `sync($object)` before `flush()` is the explicit step that admits a changed object graph into the session.

For long-lived workers, prefer one `Session` per request/job. When intentionally reusing a session, call `Session::clear()` between jobs to drop all four runtime stores. If sessions are discarded normally, an extra clear step is unnecessary.

`RelationRepresentationSynchronizer` reads relation bindings only. It projects current representation relation values into `ToManyRelationState` and `ToOneRelationState` runtime state; it does not write scalar fields, execute commands, or adopt child objects by itself. In the strict no-argument sync and pre-flush sync paths, any object discovered through a relation binding must already be tracked/adopted; an untracked related object raises `SyncException` with the relation path. This strict behavior is intentional to avoid hidden persistence from `flush()`.

For `MANY` relation bindings, the representation path must contain an iterable of objects or `null`. The synchronizer creates or reuses a `ToManyRelationState` for the concrete owner record and relation name. A `null` value is treated as an empty current item list. If the collection is not fully loaded, current items are added as known local additions without implying that absent database rows were removed. If the collection is fully loaded, the synchronizer can also remove known items that are absent from the current representation value.

For `ONE` relation bindings, the representation path must contain an object or `null`. The synchronizer creates or reuses a `ToOneRelationState` for the concrete owner record and relation name, then sets the current target. `ToOneRelationState` keeps both baseline and current target object identity so the planner can distinguish unchanged, replaced, and cleared references.

`RelationPersistencePlanner` consumes changed `ToManyRelationState` and `ToOneRelationState` instances. It resolves each relation's configured `RelationPersistencePlannerInterface`, lets that planner mutate scalar `RecordState` values and/or add commands, and returns the planned relation changes plus commands for `FlushExecutor` to finish.

The built-in relation planners are:

- `ManyToManyPersistencePlanner`: consumes `ToManyRelationState` changes for `M2MRelation`, inserting or deleting rows in the through collection.
- `HasManyPersistencePlanner`: consumes `ToManyRelationState` changes for `HasManyRelation`, propagating owner key values into added child records and nulling child outer keys for nullable removals.
- `BelongsToPersistencePlanner`: consumes `ToOneRelationState` changes for `BelongsToRelation`, propagating target key values into the owner record or nulling owner inner keys for nullable clears.
- `HasOnePersistencePlanner`: consumes `ToOneRelationState` changes for `HasOneRelation`, propagating owner key values into the current target record and, when replacing or clearing, nulling the previous target outer keys when the relation is nullable.

For scalar foreign-key relation shapes, the has-many, belongs-to, and has-one planners may write a `ValueRef` when the source key is not concrete yet. If the source key is already concrete, `RecordState::setValue()` collapses that reference immediately and the target record stores the concrete value. Relation planners require involved target objects to already be tracked so they can resolve each object to a `RecordState`. They do not adopt new objects, load missing relations, or orchestrate transactions.

## Internal Value References

`ValueRef` is an internal ORM state value used to represent a temporary dependency on another `RecordState` field. A `RecordState` may temporarily hold a `ValueRef` in its current values and dirty values. When the referenced field later contains a concrete non-null value, `RecordState::resolveValueRefs()` collapses the reference into that concrete value.

Neutral ORM persistence commands may also temporarily carry `ValueRef` values while they remain inside the flush pipeline. `CommandPlanner` and relation planners can build `InsertCommand`, `UpdateCommand`, and `DeleteCommand` instances that still contain unresolved references. `CommandValueResolver` collapses resolved command references in place before execution and rejects unresolved references at the adapter boundary.

`RecordFlusher` flushes records in waves. Before each planning attempt it resolves any now-ready references and only plans records whose command-relevant values are concrete. This allows generated-key dependencies such as a new user with a new has-many post, a new belongs-to target with a new owner, or a new has-one owner with a new target to flush as parent/source insert first, generated value merge second, dependent record command third.

Many-to-many through-row commands use `ValueRef` for mapped owner and target key fields. Because relation commands execute after `RecordFlusher`, generated owner and target keys can be merged into their records first, then `CommandValueResolver` resolves the through-row command before it reaches `CommandExecutorInterface`.

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

`RecordFlusher` preserves command result order by actual execution wave. Clean records do not block a flush, and removed keyless records are discarded without a command. Removed keyed records only require concrete delete identity values; unresolved non-identity values do not block deletion.

## Execution

`CommandExecutorInterface` is the adapter boundary:

```php
public function execute(CommandInterface $command): CommandResult;
```

`CycleCommandExecutor` is the built-in executor. It uses Cycle Database query builders for insert, update, and delete. At the adapter boundary it resolves:

- physical table name from `CollectionInterface::getTable()`
- physical column names from each field's `getColumn()`

Unknown command field names are rejected with `InvalidCommandException`; they are not passed through as raw column names.

`CycleCommandExecutor` defensively calls `CommandValueResolver::assertReady()` before using query builders. A `ValueRef` that cannot be resolved is rejected with `InvalidCommandException` instead of being bound into SQL. The executor does not build raw SQL strings. When used through `FlushExecutor`, it participates in flush-scoped transactions through `TransactionalCommandExecutorInterface`.

## Affected-row validation

`FlushScheduler` validates affected rows after each command executes. Insert, update, and delete commands default to expecting exactly one affected row. Commands can carry an explicit `ExpectedAffectedRows` policy when a different result is valid.

Many-to-many through-row deletes may use a zero-or-one affected-row policy because unlinking an already-absent through row is valid.

When the actual affected-row count does not match the command policy, flush raises `UnexpectedAffectedRowsException`. Failed commands leave record state unchanged for that operation.

## Generated Values

`CommandResult` can carry generated values keyed by field name. `RecordFlusher` merges those values into the inserted `RecordState`, marks the record clean, and indexes it by `ON\Data\Key` when the key becomes complete.

`CycleCommandExecutor` currently returns generated values only for this conservative case:

- the collection has exactly one primary-key field
- that primary-key field is marked `autoIncrement(true)`
- the insert command did not provide a non-null value for that field
- the Cycle write driver returns a non-empty generated id from `lastInsertID()`

Numeric integer strings are normalized to `int`. Generated values remain keyed by field name even when the primary-key column name differs.

The executor does not support generated values for composite keys, non-auto-increment keys, generated non-primary fields, non-primary database defaults, explicit sequence names, or full row refresh.

Generated values are merged into in-memory record state as soon as the insert command succeeds so later dependent commands in the same flush can resolve concrete foreign-key values. If a non-transactional executor writes an insert successfully and a later command fails, the record remains inspectable with the generated value but is not marked clean. Retrying that state unchanged attempts the insert again.

To recover after verifying the row exists, explicitly accept the generated key state with `RecordState::markClean($key)` and `RecordStateStore::indexKey($state)`, then retry the still-pending relation changes. Transactional executors restore in-memory record state when the transaction callback fails, so rolled-back generated values are not exposed after failure.

## Small Example

```php
use Cycle\Database\DatabaseInterface;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Session;

// Schema/table creation and Cycle Database bootstrap live outside ON\Data.
/** @var DatabaseInterface $cycleDatabase */

$registry = new Registry();
$users = $registry
    ->collection('users')
    ->table('app_users')
    ->primaryKey('id')
    ->field('id', 'int')->column('user_id')->autoIncrement(true)->end()
    ->field('name', 'string')->column('full_name')->end();

$session = new Session(new CycleCommandExecutor($cycleDatabase));

$record = $session->trackNew($users, [
    'name' => 'Ada Lovelace',
]);

$session->flush();

$generatedId = $record->getValue('id');
```

Generated ids are currently supported only for simple auto-increment primary keys. Relation persistence planning is limited to configured planners that produce scalar mutations and/or commands. Physical table and column mapping happens in `CycleCommandExecutor` using collection and field metadata.

## Public Runtime

`FlushExecutor` is the low-level orchestration service for a flush cycle. It:

- synchronizes representation fields and relation values into ORM runtime state
- plans configured relation persistence changes into scalar mutations and/or commands
- flushes changed records through `RecordFlusher`
- returns `FlushResult` with sync plans and command results

`Session` is the small runtime container around tracked representations and records. It provides public entry points for explicitly syncing an object graph and flushing planned persistence work.

This is deliberately not an `EntityManager`. There is no repository API, object proxy system, lifecycle event system, generated model layer, or relation cascade writer. `sync($object)` is graph synchronization only; it is not a cascade policy, orphan-removal policy, generated-key dependency sorter, or transaction boundary. `remove($object)` only removes an already-tracked representation that maps to one concrete record.

`Session::flush()` runs inside a database transaction when the command executor implements `TransactionalCommandExecutorInterface` (including `CycleCommandExecutor`). There is no separate transaction API on `Session`.

## Current Limits

- Scalar insert/update/delete plus configured relation persistence planning only.
- Untracked root objects passed to `sync($object)` need an explicit root `RepresentationBinding` targeting one collection; related objects use each relation binding's `getRelatedBinding()`.
- Untracked objects adopted by `sync()` become clean existing records when the binding exposes a complete non-null primary key; otherwise they become new records.
- `identify()` is the public key-only existing-record primitive. It does not query or load records from the database.
- `sync()` accepts object roots only; array input is not supported yet.
- No automatic relation cascade writes.
- No automatic graph adoption from `flush()`.
- `Session::flush()` uses executor-backed transactions when available; there is no separate transaction API on `Session`.
- No optimistic locking or stale-row revision conflict handling beyond representation sync baseline checks.
- No lazy loading.
- No repositories, `EntityManager`, `UnitOfWork`, lifecycle events, proxies, or generated model layer.
- No full database-default refresh beyond simple auto-increment primary keys.
- No batch command execution.
- No public SQL command API.
- Mutable user-defined class export is not supported yet.
- Mutable iteration is not supported yet.
- Flat projection provenance is for mutable `stdClass` query export.
