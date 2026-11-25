# Flush History

Pass a `FlushHistory` to a session or a low-level `FlushExecutor` to collect committed writes. Each flush can have a label for display in tools or logs.

```php
use ON\Data\ORM\History\FlushHistory;
use ON\Data\ORM\History\FlushReverter;
use ON\Data\ORM\Session;

$executor = $runtime->getCommandExecutor();
$history = new FlushHistory(maxEntries: 100);
$session = new Session($executor, history: $history);

// Stage writes on the session.
$session->flush('Publish draft');
$latest = $history->latest();
$timeline = $history->toArray();
```

`Session::getHistory()` returns the configured journal, if any. A `FlushExecutor` created with `history: $history` can also accept a label through `flush($context, $label)`. With no history configured, a flush builds no journal entries or value snapshots.

## Inspecting the timeline

`all()` and `since($sequence)` return commits oldest first. `since()` includes entries strictly newer than the supplied sequence. `latest()` and `get($sequence)` return `null` when an entry is missing. `count()`, `isEmpty()`, and `getMaxEntries()` report retention. The capacity must be positive and defaults to 100. Sequence IDs start at 1 and continue increasing after eviction, undo, or `clear()`. `getBaseSequence()` returns the oldest reachable state. It starts at `0`, advances to the evicted commit's sequence on eviction, and advances to the last issued sequence on `clear()`.

A flush contributes one commit after its transaction commits, with a UTC `DateTimeImmutable` in `committedAt` and one change per executed command in execution order. Rolled-back transactions and flushes that execute zero commands leave the timeline alone. A successful command that affects zero rows, such as detaching an already-missing M2M link, is included with `affectedRows` set to zero.

Changes contain the collection, operation, source, resolved identity, affected row count, and captured values. `operation` is a `FlushChangeOperation` enum and `source` is a `FlushChangeSource` enum. Their exports use the backing strings `insert`, `update`, `delete`, `custom` and `record`, `extra`, respectively. Inserts include written and recovered values. Updates include prior values for the fields written or recovered, when available. Deletes include prior values held by the tracked record or provided in the extra command's identity. Relation writes appear as foreign-key field changes and through-table commands in the same list. Custom commands can implement `InspectableCommandInterface` to expose resolved identity and payload values. They remain inspectable without an inverse.

The history can survive `Session::clear()` or outlive a request-scoped session. Entries keep collection metadata and detached values. Dates are copied to immutable values, arrays are copied recursively, and enum cases may be retained. `toArray()` on the journal, commit, or change exports values as arrays and scalars. Dates use ISO 8601, enums use their backing value or case name, and binary strings use a `base64` field. Maps with binary keys export their key/value pairs under `entries`. Opaque objects, resources, recursive arrays, and arrays nested more than 64 levels are omitted and reported under `unavailableFields`.

## Subscribing to changes

`onCommit()` calls the listener with a `FlushCommit` after the session finalizes its records, clears pending relation changes, and records the commit. `onRevert()` calls the listener after a successful revert trims the journal. It receives the undone commits newest first and the sequence reached. Empty flushes and no-op reverts emit no event.

Both methods return an unsubscribe closure that can be called multiple times, including from a listener. Callbacks run in registration order. Unsubscribing before a callback's turn skips it, and registering a callback during delivery takes effect on the next event.

Listeners can inspect the updated journal. Calling `flush()`, `revertLast()`, `revertTo()`, or `FlushHistory::clear()` on the same journal during delivery raises `HistoryException`. A listener exception propagates to the caller and stops delivery of that event. The database transaction and journal update have already completed, and the journal releases its write guard.

## Reverting writes

```php
$reverter = new FlushReverter($history, $executor);
$undone = $reverter->revertLast();
// Or use $reverter->revertTo($sequence) to undo every retained commit after that sequence.
$session->clear();
```

Use the executor for the same database and command mappings as the recorded writes. `revertLast()` returns the undone commit. `revertTo()` accepts any retained commit's sequence or `getBaseSequence()` and returns the undone commits newest first. Target the base to undo every retained commit in one transaction. Target the newest entry, or the base of an empty journal, to get an empty list. An empty `revertLast()` or any other target for `revertTo()` raises `HistoryRollbackException`, a subtype of `HistoryException`.

The reverter checks the whole range before running inverse commands. Inserts are undone by key, updates restore captured prior values for the changed fields, and deletes reinsert a complete prior row. A through row with every field in its identity can be restored after a delete. Known zero-row operations require no inverse. The reverter checks that each inverse affects exactly one row and applies them in reverse execution order inside one transaction. After commit it trims the undone entries from the timeline. A validation or compensation failure before commit leaves both the database and journal at their pre-revert state.

`isReversible()` is available on changes and commits. Check a change's `irreversibleReason` for details. Compensation requires a resolved identity and captured values. Blind PATCH updates with missing prior fields, partial deletes, unsupported commands, and updates with database-managed fields that could regenerate during undo are refused. The writable query hydrator currently omits `NULL` fields from a record's initial snapshot. An omitted value counts as missing. An explicit `null` in a trusted `RecordState::clean()` snapshot is preserved and can be restored.

Flush and revert must own their transaction for the journal to reflect committed work. The PDO runtime raises `HistoryException` if a history-enabled flush or revert starts inside an existing transaction. Custom adapters must provide the same commit guarantee and can report active transactions via `TransactionStateAwareInterface`. Use a separate journal per database or write scope. A shared journal also raises `HistoryException` on overlapping flushes, reverts, or journal clears.

The reverter operates on the database and journal. Clear or discard sessions holding affected rows before using those sessions again, and reload any DTOs the application still holds. `Session::clear()` also discards pending in-memory writes. History represents commands and available ORM baselines. Safe use assumes those baselines are current and the recorded rows have not been modified outside the timeline. Database triggers, cascades, external writers, generated identity sequences, and other side effects may produce changes outside the journal's view.
