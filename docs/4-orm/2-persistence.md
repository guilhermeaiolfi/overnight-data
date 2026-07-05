# ORM Persistence

The current ORM persistence layer writes scalar record fields and plans configured relation changes. It is intentionally small: representations synchronize into `RecordState` and relation state, dirty/new/removed records become neutral commands, configured relation planners can mutate scalar record state or add commands, and an adapter executes those commands against a backend.

It is not a full entity manager, unit of work, automatic relation cascade writer, or transaction coordinator.

## Flow

```text
ScalarRepresentationSynchronizer
  object scalar fields -> RecordState scalar values

RelationRepresentationSynchronizer
  object relation paths from RepresentationBinding -> RelatedCollection / RelatedReference runtime state

RelationPersistencePlanner
  RelatedCollection / RelatedReference changes -> RecordState mutations and/or extra persistence commands

RecordFlusher
  RecordState lifecycle/dirty state -> InsertCommand / UpdateCommand / DeleteCommand

CommandExecutorInterface
  executes neutral commands
```

`FlushExecutor` coordinates the pipeline as representation sync, relation persistence planning, then record flushing. `Session` owns the in-memory `TrackedRepresentationMap` and `RecordStateMap` used by that flow.

## State And Sync

`RecordState` is the persistence source of truth. It stores canonical PHP values keyed by collection field name, the original clean snapshot, lifecycle state, and identity through `ON\Data\Key`.

Representations are user-facing objects or values. A tracked representation can drift from its record state until synchronization happens. `ScalarRepresentationSynchronizer` reads field bindings only and applies planned representation field updates into `RecordState` while preserving the conflict rules from the ORM foundation: a representation based on a stale record revision cannot silently overwrite a newer record value.

`Session::sync(?object $representation = null, ?RepresentationBinding $binding = null)` is the explicit graph entry point. With no argument it strictly syncs already-tracked representations. With an object it walks that object's explicit `RepresentationRelationBinding` graph, syncs scalar values into `RecordState`, and syncs relation values into `RelatedCollection` / `RelatedReference` runtime state. It returns `SyncResult`, containing scalar sync plans and touched relation changes. It does not plan relation persistence, flush records, execute commands, mark records clean, or clear relation changes.

For an untracked root object, `sync($object, $binding)` requires a root `RepresentationBinding` that targets exactly one root collection through its field bindings and/or relation owner bindings. This entry point is for entity-shaped object bindings. A binding with no resolvable collection, or a mixed/projection binding spanning multiple collections, cannot create a new root `RecordState` and raises `StateException`. When the binding is valid, the root is tracked from that binding, then the method follows only explicit relation bindings. For `MANY` relation bindings, `null` means an empty collection and a non-null value must be iterable objects. For `ONE` relation bindings, `null` means no target and a non-null value must be an object. Each discovered untracked related object is tracked with that relation binding's `getRelatedBinding()`, and the walk continues recursively while guarding object identity cycles. Newly discovered records are currently adopted as `NEW` and initialized from the binding's field paths; already-tracked objects keep their existing lifecycle.

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

`Session::flush()` still calls strict representation sync automatically before relation persistence planning and record flushing. That pre-flush sync does not adopt new untracked related objects. Calling `sync($object)` before `flush()` is the explicit step that admits a changed object graph into the session.

`RelationRepresentationSynchronizer` reads relation bindings only. It projects current representation relation values into `RelatedCollection` and `RelatedReference` runtime state; it does not write scalar fields, execute commands, or adopt child objects by itself. In the strict no-argument sync and pre-flush sync paths, any object discovered through a relation binding must already be tracked/adopted; an untracked related object raises `SyncException` with the relation path. This strict behavior is intentional to avoid hidden persistence from `flush()`.

For `MANY` relation bindings, the representation path must contain an iterable of objects or `null`. The synchronizer creates or reuses a `RelatedCollection` for the concrete owner record and relation name. A `null` value is treated as an empty current item list. If the collection is not fully loaded, current items are added as known local additions without implying that absent database rows were removed. If the collection is fully loaded, the synchronizer can also remove known items that are absent from the current representation value.

For `ONE` relation bindings, the representation path must contain an object or `null`. The synchronizer creates or reuses a `RelatedReference` for the concrete owner record and relation name, then sets the current target. `RelatedReference` keeps both baseline and current target object identity so the planner can distinguish unchanged, replaced, and cleared references.

`RelationPersistencePlanner` consumes changed `RelatedCollection` and `RelatedReference` instances. It resolves each relation's configured `RelationPersistencePlannerInterface`, lets that planner mutate scalar `RecordState` values and/or add commands, and returns the planned relation changes plus commands for `FlushExecutor` to finish.

The built-in relation planners are:

- `ManyToManyPersistencePlanner`: consumes `RelatedCollection` changes for `M2MRelation`, inserting or deleting rows in the through collection.
- `HasManyPersistencePlanner`: consumes `RelatedCollection` changes for `HasManyRelation`, copying owner key values into added child records and nulling child outer keys for nullable removals.
- `BelongsToPersistencePlanner`: consumes `RelatedReference` changes for `BelongsToRelation`, copying target key values into the owner record or nulling owner inner keys for nullable clears.
- `HasOnePersistencePlanner`: consumes `RelatedReference` changes for `HasOneRelation`, copying owner key values into the current target record and, when replacing or clearing, nulling the previous target outer keys when the relation is nullable.

Relation planners require involved target objects to already be tracked so they can resolve each object to a `RecordState`. They do not adopt new objects, load missing relations, order commands around generated ids, or orchestrate transactions.

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

## Execution

`CommandExecutorInterface` is the adapter boundary:

```php
public function execute(CommandInterface $command): CommandResult;
```

`CycleCommandExecutor` is the built-in executor. It uses Cycle Database query builders for insert, update, and delete. At the adapter boundary it resolves:

- physical table name from `CollectionInterface::getTable()`
- physical column names from each field's `getColumn()`

Unknown command field names are rejected with `InvalidCommandException`; they are not passed through as raw column names.

The executor does not build raw SQL strings and does not manage transactions.

## Generated Values

`CommandResult` can carry generated values keyed by field name. `RecordFlusher` merges those values into the inserted `RecordState`, marks the record clean, and indexes it by `ON\Data\Key` when the key becomes complete.

`CycleCommandExecutor` currently returns generated values only for this conservative case:

- the collection has exactly one primary-key field
- that primary-key field is marked `autoIncrement(true)`
- the insert command did not provide a non-null value for that field
- the Cycle write driver returns a non-empty generated id from `lastInsertID()`

Numeric integer strings are normalized to `int`. Generated values remain keyed by field name even when the primary-key column name differs.

The executor does not support generated values for composite keys, non-auto-increment keys, generated non-primary fields, non-primary database defaults, explicit sequence names, or full row refresh.

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

Generated ids are currently supported only for simple auto-increment primary keys. Relation persistence planning is limited to configured planners that produce scalar mutations and/or commands; transactions are not orchestrated yet. Physical table and column mapping happens in `CycleCommandExecutor` using collection and field metadata.

## Public Runtime

`FlushExecutor` is the low-level orchestration service for a flush cycle. It:

- synchronizes representation fields and relation values into ORM runtime state
- plans configured relation persistence changes into scalar mutations and/or commands
- flushes changed records through `RecordFlusher`
- returns `FlushResult` with sync plans and command results

`Session` is the small runtime container around tracked representations and records. It provides public entry points for explicitly syncing an object graph and flushing planned persistence work.

This is deliberately not an `EntityManager`. There is no repository API, object proxy system, lifecycle event system, generated model layer, or relation cascade writer. `sync($object)` is graph synchronization only; it is not a cascade policy, orphan-removal policy, generated-key dependency sorter, or transaction boundary.

## Current Limits

- Scalar insert/update/delete plus configured relation persistence planning only.
- Untracked root objects passed to `sync($object)` need an explicit root `RepresentationBinding` targeting one collection; related objects use each relation binding's `getRelatedBinding()`.
- `sync()` accepts object roots only; array input is not supported yet.
- No automatic relation cascade writes.
- No automatic graph adoption from `flush()`.
- No transaction orchestration.
- No optimistic locking, stale-row detection, or affected-row conflict handling.
- No lazy loading.
- No repositories, `EntityManager`, `UnitOfWork`, lifecycle events, proxies, or generated model layer.
- No full database-default refresh beyond simple auto-increment primary keys.
- No batch command execution.
- No public SQL command API.
