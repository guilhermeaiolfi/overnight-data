# ORM Foundation

This guide describes the current ORM model and component responsibilities. See [`persistence.md`](./persistence.md) for command execution, [`session-save-api.md`](./session-save-api.md) for write examples, and [`representation-schema.md`](./representation-schema.md) for the schema model.

The current implementation includes production record state, representation tracking, scalar sync, relation representation sync, relation persistence planning, scalar command planning, flush orchestration, session orchestration, and Cycle-backed scalar command execution. It still does not include lazy loading, generated repositories, service containers, events, proxy objects, `EntityManager`, or `UnitOfWork`.

The library remains standalone `ON\Data`. The Registry remains the owner of one plain-array definition tree, and definition objects remain wrappers over that array. ORM state, unit-of-work behavior, identity maps, lazy loading, relation write planning, and representation synchronization rules belong outside the Registry.

## Persistence Model

The ORM foundation is not the classic "entity object = row" model. The flow is:

```text
database/query -> RecordState -> Representation
Representation -> sync() -> RecordState -> flush() -> database
```

`Session::flush()` applies staged write intents, synchronizes tracked objects, plans relation changes, and executes commands transactionally. `sync()` provides early synchronization and validation without database writes. The ORM persists synchronized record state. The necessary read and write steps, and where they currently live, are in [`../read-write.md`](../read-write.md).

`FieldType` implementations and representations remain the conversion authority. SQL dialect differences stay delegated to Cycle Database / DBAL integration.

Mapping uses `map($source)->to(...)`; query object targets use the same direction.

## RecordState

A `RecordState` is the canonical in-memory state for one persistent record identity. It owns the current values and in-memory revision history, and it is the thing `flush()` writes.

It stores:

- collection name;
- complete or known field values in canonical PHP representation;
- original clean snapshot;
- current synchronized values;
- dirty fields;
- lifecycle state;
- identity values through `ON\Data\Key`, including composite keys;
- in-memory revision history used by representation baselines and rollback. Relation state is stored separately in `RelationStateStore`.

Identity is first-class through the existing `ON\Data\Key` type. It includes the collection name, primary-key values in definition key order, composite-key support, and canonical PHP values.

Each `RecordState` also has a stable ORM record target hash via `getStateHash()`. For clean persisted records this can be the `Key` hash. For new unsaved records it is a local in-memory tracking hash that stays stable if the record later receives a database key through `markClean($key)`. This hash is not database identity, persistence state, or optimistic locking; it exists so runtime state items can distinguish multiple unsaved records in the same PHP process/session.

## Representation

A representation is a PHP value that exposes data to the user. It may be a class object, `stdClass`, array projection, DTO, or a future dynamic model.

Representations are not automatically the source of truth. They can drift from the record state until explicitly synchronized.

## RepresentationState

A `RepresentationState` is concrete runtime state for an applied `RepresentationSchema`. It stores baseline record revisions for one representation object, but it does not store the object itself.

It stores:

- the structure-only `RepresentationSchema`;
- `RepresentationFieldStateItem` entries that attach structural field schemas to concrete `RecordState` objects and baseline record revisions;
- `RepresentationRelationStateItem` entries that attach structural relation schemas to concrete owner `RecordState` objects;
- writable/read-only flags per mapped slot through field schemas.

Relation membership and completeness live in `RelationStateStore`.

It does not store baseline field values. When sync needs an old value, it asks the owning `RecordState` history for the value at the tracked baseline revision.

Do not use `RepresentationState` as a template. It is only for concrete runtime state after a schema has been applied and baseline record revisions are known. The owning `RepresentationStateStore` keeps the association between the PHP object and its `RepresentationState`.

A single representation may map to multiple records and collections:

```php
$row = $query
    ->select(
        $u->id,
        $u->name->as('userName'),
        $u->posts->id->as('postId'),
        $u->posts->title->as('postTitle'),
        $u->posts->author->id->as('authorId'),
        $u->posts->author->name->as('authorName'),
    )
    ->to(stdClass::class)
    ->writable($session)
    ->fetchOne();

$row->userName = 'New user';
$row->postTitle = 'New post';
$row->authorName = 'New author';

$session->sync($row);
$session->flush();
```

This is valid only when the ORM knows lineage:

```text
userName   -> users[id].name
postTitle  -> posts[id].title
authorName -> authors[id].name
```

`sync($row)` updates three `RecordState` instances. `flush()` writes the dirty record states.

## Sync

Use `create()` for a new row and `update()` for an existing row. Both stage intent; a root needs a supplied or tracked schema, while a nested object can inherit its owning relation's schema. Primary-key presence does not choose create versus update.

```php
$session->update($dto, $schema);
$session->flush(); // admit the staged graph, synchronize, then write
```

`sync($object)` walks its schema graph and admits untracked children. An untracked object without explicit update intent defaults to a new record. `sync($object, $schema)` can admit a new entity-shaped root; mixed projections need existing concrete bindings or the staged write API. See [`session-save-api.md`](./session-save-api.md) for lifecycle and flat-source operations.

`sync()` without an argument applies pending intents and synchronizes tracked objects. Pre-flush synchronization uses the same strict tracked-object path. Merely assigning an untracked child to an already-tracked object is insufficient: stage the owner with `update($owner)` or walk it with `sync($owner)` first. Unresolved staged intents fail before database writes.

Deletion remains explicit:

```php
$session->remove($user); // requires an already-tracked object representing one root record
$session->flush();
```

`identify($collection, $key)` attaches a key-only existing reference without querying or writing its fields. `detach($child, $owner, $relation)` expresses unlinking separately from deleting the child row.

Write admission happens before the flush transaction. When persistence fails, admitted writes remain tracked for retry; record values, revision history, and tracked field baselines are restored together.

## Conflict Detection

Synchronization is conflict-aware, whether requested explicitly or performed during flush.

Field-level conflict rule:

```text
If a representation changed field X,
and the record value at the representation baseline revision for X is different from current `RecordState` value for X,
and the representation current value for X is different from current RecordState value for X,
then sync must reject with a representation conflict.
```

Example:

```text
RecordState value = A1

Representation 1 baseline = A1
Representation 2 baseline = A1

Representation 2 changes A1 -> A2
$session->sync($representation2)

RecordState value = A2

Representation 1 changes A1 -> A3
$session->sync($representation1)
```

The default result is conflict. The ORM must not silently overwrite `A2` with `A3` because representation 1 was based on stale data.

Scalar synchronization validates all plans as one batch: differing proposed values for the same concrete record field are rejected before any plan is applied. Missing properties are skipped; a present `null` is a value. Read-only fields are not written.

Explicit staged `update()` calls have ordered PATCH semantics: later patches to the same field win, as with successive explicit synchronization of those patches. This differs from conflicting edits to independently loaded writable objects. There is no public overwrite conflict policy or `Session::refresh()`.

## Runtime Stores

`SessionContext`, owned by `Session`, holds four runtime stores:

```text
ON\Data\Key / RecordState state hash -> RecordState
weak representation object -> RepresentationState
owner record + relation name -> to-many / to-one relation state
weak representation object -> staged RepresentationIntent
```

Classic ORMs usually map identity to entity object. This ORM maps identity to record state, then allows many representations over that state.

`RecordStateStore` is the record-state registry. It indexes each known state by its stable local state hash and, when available, by its database `ON\Data\Key` hash. A new record keeps its local state hash after it receives a generated database key through `RecordState::markClean($key)`; the state hash must not be rewritten to the key hash, because existing runtime state items use that stable local handle.

The store is responsible for aliasing both handles to the same `RecordState`. Scalar insert/flush logic calls `RecordStateStore::indexKey($state)` after assigning a generated key so keyed references can resolve the same state that was previously known only by local state hash.

`QuerySourceIdentities` reads primary-key paths from the assembled identity overlay using prepared schema sources. It holds neither per-row records nor a second locator map. Source paths distinguish different occurrences of the same collection. Structural schemas have no concrete record to resolve until adoption creates state items.

`RecordStateStore` is not an object identity store. It strongly owns record state for the current session/unit of work. `RelationStateStore` also strongly owns to-many and to-one relation runtime state for the session. `RepresentationIntentStore` uses weak object keys for staged create/update intent and source operations.

`RepresentationStateStore` remains separate and tracks PHP representation object identity with weak keys. It does not keep representation objects alive in long-lived workers. When a representation object becomes otherwise unreachable, its store entry can disappear after garbage collection.

Use one `Session` per request/job in long-lived workers. If a session is intentionally reused, call `Session::clear()` to clear record, representation, relation, and intent stores between jobs.

## SelectQuery Integration

`SelectQuery` is the read-query API.

Result targets keep the existing mapping direction:

```php
$query->to(User::class);
$query->to(UserSummary::class);
$query->to(stdClass::class);
// Omit to() for array results.
```

A `SelectQuery` with a target representation and no explicit `select()` uses the root collection's default scalar field selection. This is the normal root entity load:

```php
$users = query($users)
    ->to(User::class)
    ->fetchAll();
```

Conceptually, that means:

```text
select the root collection's default scalar fields
map/hydrate each row to User
track one root RecordState per row when ORM tracking is active
```

Root default fields are all normal root scalar fields.

Default root selection is root-only. It must not auto-load relations; pass a `RelationRef` to `select()` (or configure the ref before fetch) for nested loading:

```php
$u = query($users);

$users = $u
    ->select(
        $u->posts->select('id', 'title'),
    )
    ->to(User::class)
    ->fetchAll();
```

A bare `$u->posts` in `select()` loads all visible related fields. Relation-only `select()` keeps default root scalars.

`stdClass` and array targets use the same omitted-selection rule:

```php
$rows = query($users)
    ->to(stdClass::class)
    ->fetchAll();

$rows = query($users)->fetchAll(); // arrays
```

Both produce root field representations. When ORM tracking is active, each `stdClass` object can be tracked as a root record representation.

An explicit scalar selection replaces the default root scalar selection:

```php
$rows = query($users)
    ->select($u->id, $u->name)
    ->to(UserSummary::class)
    ->fetchAll();
```

That uses the explicit selection. Existing implicit selections can provide hidden identity fields required by the query shape. `writable()` does not add primary-key columns; missing fetched identity raises an error rather than adopting a fetched object as new.

Writable results require lineage. A selected direct field can be writable if identity data is available:

```php
$u->name->as('userName');
```

An expression is read-only by default:

```php
$u->name->upper()->as('title');
```

Expressions cannot safely reverse back into `users.name` without explicit support.

`writable($session)` requires a supported mutable object target: `stdClass` or a mutable DTO. Arrays are query projections, not tracked root objects. Partial object results can be writable when their selected fields have known lineage and identity. See [`writable-select-query-projections.md`](./writable-select-query-projections.md).

Hidden required fields must not appear in the final mapped representation unless explicitly selected or mapped.

Relation loading uses `RelationRef` branch configuration, including refs passed to `SelectQuery::select()`. There is no separate `with()` API.

Relations remain class-based and pluggable. Relation definitions remain the source of relation intent; ORM write behavior is implemented by relation persistence planners that interpret those definitions.

## Schema Metadata

Collection definitions own field types, relations, storage names, and primary keys. Representation schemas refer to that metadata rather than duplicating it in an entity metadata registry.

`RepresentationSchema` is the reusable mapping shape for roots and related items. `RepresentationState` binds it to concrete records.

The distinction is:

```text
RepresentationSchema
    reusable mapping shape

RepresentationState
    concrete record bindings + baseline record revisions (object association lives in the store)
```

Concrete runtime attachment may need local record state that is more precise than collection + field + `sourcePath`. A structure-only schema is reusable, but it is ambiguous for multiple new child records:

```text
posts[no-key].title
posts[no-key].title
```

`RepresentationSchema` therefore remains structural only:

- field schemas store representation path, collection, field, writability, and `sourcePath`;
- relation schemas store representation path, owner collection, relation name, and the reusable related schema;
- concrete records live in `RepresentationFieldStateItem` and `RepresentationRelationStateItem`.

State items point to concrete in-memory `RecordState` objects, including new unsaved records before any database key exists. Their stable local handle is `RecordState::getStateHash()`.

Sources of schema information:

1. Query selection lineage.
2. Collection/view definitions.
3. Mapper attributes such as `MapFrom` and `MapTo`.
4. Optional explicit schema for manually attached/new representations.

`MapFrom` and `MapTo` are naming hints. They are not enough for ORM persistence because the ORM also needs source collection, field lineage, identity, read/write status, and relation state.

## stdClass

`stdClass` is a first-class representation target.

A `stdClass` may be persistable if it has representation schema and lineage. A random `stdClass` without schema is only a projection unless explicitly attached with enough information.

## Partial Results

Partial arrays are query projections. Partial `stdClass` and mutable DTO objects can be writable for selected direct fields with known lineage and identity. Missing fields are not overwritten during sync; a present `null` is a write. Expressions are read-only by default.

## Relation loading

There is no lazy loading. Query-driven structured loads record membership completeness on `RepresentationRelationSchema` (`RelationLoadKnowledge`: Unknown / Partial / Full). Unqualified relation selections are Full; relation `where` / `limit` / `offset` are Partial. Only Full collections may remove absent members during reconciliation. An unloaded or partial relation must never be treated as the complete database membership.

## Cascades

`ON\Data` Session persistence does **not** interpret relation `cascade()` / `isCascade()` today. Built-in planners use `exclusive()` (and nullability) for unlink behavior instead.

Relation definitions still store `cascade` and `load` metadata because external tools (notably Overnight’s Cycle ORM schema generator) read those values when bridging definitions into Cycle schema options. Treat them as interoperability metadata for that path, not as ON\Data flush semantics.

ON\Data also does not implement lazy loading. Relation `load('lazy'|'eager')` is similarly stored for external schema consumers; query relation loading is always explicit through `RelationRef` configuration.

## Relation State

`ToManyRelationState` owns relation deltas and knowledge completeness for to-many relations. Its load knowledge is internal to the runtime state:

```text
unloaded
partial
full
```

Rules:

- adding a child to an unloaded collection is valid;
- removing one known child from an unloaded collection can be valid;
- assigning an array to an unloaded collection supplies additions, not a complete replacement;
- an unloaded collection must never be treated as empty.

Relation tracking state must be ORM-owned. A DTO may expose a plain array, but that array alone cannot distinguish:

```text
unloaded
partially loaded
fully loaded
loaded empty
full replacement
local projection change
```

`RelationRepresentationSynchronizer` reads array membership at synchronization and reconciles it with `ToManyRelationState`, which owns the completeness knowledge and pending deltas. Adding an item to a partial or unknown relation is supported; removing absent members is allowed only for a fully loaded relation. `Session::detach()` provides explicit unlink intent without implying a complete collection.

For example, a new child can be attached through the owner's schema before flush:

```php
$user->posts[] = $post;
$session->create($post);
$session->sync($user); // walk the owner's relation schemas and adopt the child
$session->flush();
```

The runtime relation state also supports explicit add/remove operations:

```php
$relationState->add($post);
$relationState->remove($post);
```

The default DTO array is not itself a change-notifying collection wrapper.

`ToManyRelationState` owns the owner record, relation name, load state, known items, pending additions/removals, and reusable child schema. Known items are the in-memory view, not necessarily the full database relation. `isEmptyKnown()` does not prove the database relation is empty.

Query hydration seeds baseline membership directly. Inbound synchronization onto an unloaded collection treats present items as pending additions. `ToOneRelationState` keeps baseline and current target identities so the planner can distinguish unchanged, replaced, and cleared references.

Relation state records intent; configured persistence planners translate it into foreign-key changes, through-row commands, or deletion where the relation's exclusive policy requires it. A backing collection adapter is not required, and a generic collection library does not own these ORM semantics.

## Synchronization responsibilities

`RepresentationReader` reads scalar bags through the mapper and relation paths from live objects. It distinguishes missing properties from present `null` values and supports numeric array path segments. It does not persist values.

`ScalarRepresentationSynchronizer` reads current values, detects conflicts, and produces `SyncPlan` instances containing field updates and conflicts. It validates the batch before applying plans. `SyncPlan::apply()` writes planned values into records and acknowledges field baselines.

Several field updates can target one record. Persistence planning reads that record's aggregated dirty values and can emit one update command for all its changed fields. Sync plans do not assemble SQL commands.

## Where to make changes

| Change | Starting point |
| --- | --- |
| Public write operations and staged intent admission | `Session` |
| Bind schema sources and admit object graphs | `RepresentationAdoptionEngine` |
| Prepare and track writable query results | `WritableQueryResultTracker` |
| Field conflicts, sync planning, and batch application | `ScalarRepresentationSynchronizer` |
| Reconcile exposed relation values with runtime state | `RelationRepresentationSynchronizer` |
| Translate relation deltas into persistence work | `RelationPersistencePlanner` and the relation's configured planner |
| Transactional flush orchestration and rollback | `FlushExecutor` |
| Schedule scalar/dependent commands and finalize record state | `FlushScheduler` |
| Convert command values or execute backend commands | `ConvertingCommandExecutor` / `CycleCommandExecutor` |

`WritableQueryResultTracker` passes results to `RepresentationAdoptionEngine::attach()` to bind sources and walk relation children. `Session::adopt()` stores ready state; it does not perform another graph walk, synchronize values, or execute writes.

Normal callers use `Session::flush()`. `FlushScheduler::run()` is a lower-level scheduling operation, not a substitute for session synchronization, relation planning, or transactional orchestration. See [`persistence.md`](./persistence.md) for those boundaries.

## Current limits

The ORM does not provide lazy loading, generated repositories, proxy objects, a service container, an event system, an entity metadata registry, or separate `EntityManager` / `UnitOfWork` classes. There is no session transaction API, database optimistic locking, or `Session::refresh()`.

Write-path recovery of omitted insert columns and `DatabaseGenerator` update columns is implemented. It does not provide general refresh of an existing row.
