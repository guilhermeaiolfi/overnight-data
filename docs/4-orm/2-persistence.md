# ORM Scalar Persistence

The current ORM persistence layer writes scalar record fields. It is intentionally small: representations synchronize into `RecordState`, dirty/new/removed records become neutral commands, and an adapter executes those commands against a backend.

It is not a full entity manager, unit of work, relation write planner, or transaction coordinator.

## Flow

```text
representation object
  -> RepresentationSynchronizer
  -> RecordState
  -> CommandPlanner
  -> InsertCommand / UpdateCommand / DeleteCommand
  -> CommandExecutorInterface
  -> CommandResult
  -> RecordFlusher syncs successful results back into RecordState
```

`FlushExecutor` coordinates representation sync and record flushing. `Session` owns the in-memory `TrackedRepresentationMap` and `RecordStateMap` used by that flow.

## State And Sync

`RecordState` is the persistence source of truth. It stores canonical PHP values keyed by collection field name, the original clean snapshot, lifecycle state, and identity through `ON\Data\Key`.

Representations are user-facing objects or values. A tracked representation can drift from its record state until synchronization happens. `RepresentationSynchronizer` applies planned representation field updates into `RecordState` while preserving the conflict rules from the ORM foundation: a representation based on a stale record revision cannot silently overwrite a newer record value.

The synchronizer only writes scalar field values into known record states. Relation collection mutations are tracked separately as intent and are not planned as database writes yet.

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

Generated ids are currently supported only for simple auto-increment primary keys. Relation writes are not planned by this scalar flush, and transactions are not orchestrated yet. Physical table and column mapping happens in `CycleCommandExecutor` using collection and field metadata.

## Public Runtime

`FlushExecutor` is the low-level orchestration service for a flush cycle. It:

- synchronizes tracked representations into records
- flushes changed records through `RecordFlusher`
- returns `FlushResult` with sync plans and command results

`Session` is the small runtime container around tracked representations and records. It provides the current public entry point for syncing/flushing already-tracked scalar records.

This is deliberately not an `EntityManager`. There is no repository API, object proxy system, lifecycle event system, generated model layer, or relation cascade writer.

## Current Limits

- Scalar insert/update/delete only.
- No relation write planning.
- No transaction orchestration.
- No optimistic locking, stale-row detection, or affected-row conflict handling.
- No lazy loading.
- No repositories, `EntityManager`, `UnitOfWork`, lifecycle events, proxies, or generated model layer.
- No full database-default refresh beyond simple auto-increment primary keys.
- No batch command execution.
- No automatic relation cascade writes.
- No public SQL command API.
