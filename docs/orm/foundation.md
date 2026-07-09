# ORM Foundation

This document records the foundation concepts for the `ON\Data` ORM. Some early sections describe the intended architecture before the runtime existed; current persistence behavior is documented in [`persistence.md`](./persistence.md), and the recursive representation schema model is documented in [`representation-schema.md`](./representation-schema.md).

The current implementation includes production record state, representation tracking, scalar sync, relation representation sync, relation persistence planning, scalar command planning, flush orchestration, session orchestration, and Cycle-backed scalar command execution. It still does not include lazy loading, generated repositories, service containers, events, proxy objects, `EntityManager`, or `UnitOfWork`.

The library remains standalone `ON\Data`. The Registry remains the owner of one plain-array definition tree, and definition objects remain wrappers over that array. ORM state, unit-of-work behavior, identity maps, lazy loading, relation write planning, and representation synchronization rules belong outside the Registry.

## Persistence Model

The ORM foundation is not the classic "entity object = row" model. The planned flow is:

```text
database/query -> RecordState -> Representation
Representation -> sync() -> RecordState -> flush() -> database
```

The ORM persists synchronized record state, not PHP objects directly.

`FieldType` implementations and representations remain the conversion authority. SQL dialect differences stay delegated to Cycle Database / DBAL integration.

Keep the existing mapper direction: `map($source)->to(...)`. ORM result-target work should extend that mental model instead of introducing a reversed or parallel mapper vocabulary.

Public getters should use `getSomething()` naming. Keep code simple and readable, and avoid speculative containers, event systems, proxy systems, or duplicated metadata.

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
- relation state metadata later.

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
- writable/read-only flags per mapped slot through field schemas;
- relation collection loaded state later.

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
    ->fetchOne();

$row->userName = 'New user';
$row->postTitle = 'New post';
$row->authorName = 'New author';

$em->sync($row);
$em->flush();
```

This is valid only when the ORM knows lineage:

```text
userName   -> users[id].name
postTitle  -> posts[id].title
authorName -> authors[id].name
```

`sync($row)` updates three `RecordState` instances. `flush()` writes the dirty record states.

## Sync

Use `sync()` for both new and changed representations:

```php
$em->sync($user);
$em->flush();
```

Do not design a public `persist()` API for create/update. The ORM decides whether the representation is new, whether it points at a new record, or whether it mutates already-known record state.

Deletion remains explicit:

```php
$em->remove($user);
$em->flush();
```

The exact deletion method name may remain open until the write API is designed.

## Conflict Detection

`sync()` must be explicit and conflict-aware.

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
$em->sync($representation2)

RecordState value = A2

Representation 1 changes A1 -> A3
$em->sync($representation1)
```

The default result is conflict. The ORM must not silently overwrite `A2` with `A3` because representation 1 was based on stale data.

Future explicit policies may exist:

```php
$em->sync($representation, ConflictPolicy::OVERWRITE);
$em->refresh($representation);
```

Default `sync()` remains safe.

## Runtime Stores

Avoid object-first identity-map terminology. `Session` owns runtime stores:

```text
ON\Data\Key / RecordState state hash -> RecordState
weak representation object -> RepresentationState
```

Classic ORMs usually map identity to entity object. This ORM maps identity to record state, then allows many representations over that state.

`RecordStateStore` is the record-state registry. It indexes each known state by its stable local state hash and, when available, by its database `ON\Data\Key` hash. A new record keeps its local state hash after it receives a generated database key through `RecordState::markClean($key)`; the state hash must not be rewritten to the key hash, because existing runtime state items use that stable local handle.

The store is responsible for aliasing both handles to the same `RecordState`. Scalar insert/flush logic calls `RecordStateStore::indexKey($state)` after assigning a generated key so keyed references can resolve the same state that was previously known only by local state hash.

Flat projection identity resolution first uses visible field values, then falls back to `QueryRepresentationIdentityColumns` for hidden primary-key result columns. Structural schemas have no concrete record to resolve until adoption creates `RepresentationFieldStateItem` or `RepresentationRelationStateItem` entries.

`RecordStateStore` is not an object identity store. It strongly owns record state for the current session/unit of work. `ToManyRelationStore` and `ToOneRelationStore` also strongly own relation runtime state for the session.

`RepresentationStateStore` remains separate and tracks PHP representation object identity with weak keys. It does not keep representation objects alive in long-lived workers. When a representation object becomes otherwise unreachable, its store entry can disappear after garbage collection.

Use one `Session` per request/job in long-lived workers. If a session is intentionally reused, call `Session::clear()` to clear record, representation, collection, and reference stores between jobs.

## SelectQuery Integration

Do not introduce `EntityQuery`. The existing `SelectQuery` remains the one read-query API.

Result targets keep the existing mapping direction:

```php
$query->to(User::class);
$query->to(UserSummary::class);
$query->to(stdClass::class);
$query->to([]);
```

A `SelectQuery` with a target representation and no explicit `select()` uses the root collection's default scalar field selection. This is the normal root entity load:

```php
$users = query($users)
    ->where(fn ($u) => $u->active->equals(true))
    ->to(User::class)
    ->fetchAll();
```

Conceptually, that means:

```text
select the root collection's default scalar fields
map/hydrate each row to User
track one root RecordState per row when ORM tracking is active
```

The current v1.0 implementation defines root default fields as all normal root scalar fields. Later releases may become smarter based on target representation requirements.

Default root selection is root-only. It must not auto-load relations; explicit relation loading still goes through the existing `RelationRef` / `select()` model:

```php
$u = query($users);

$users = $u
    ->select(
        $u->posts->fields('id', 'title'),
    )
    ->to(User::class)
    ->fetchAll();
```

Do not add `with()` for this.

`stdClass` and array targets use the same omitted-selection rule:

```php
$rows = query($users)
    ->to(stdClass::class)
    ->fetchAll();

$rows = query($users)
    ->to([])
    ->fetchAll();
```

Both produce root field representations. When ORM tracking is active, each `stdClass` object can be tracked as a root record representation.

Any explicit `select(...)` disables the default root field selection:

```php
$rows = query($users)
    ->select($u->id, $u->name)
    ->to(UserSummary::class)
    ->fetchAll();
```

That uses the explicit selection only, plus hidden required fields for identity/tracking if needed.

Writable results require lineage. A selected direct field can be writable if identity data is available:

```php
$u->name->as('userName');
```

An expression is read-only by default:

```php
$u->name->upper()->as('title');
```

Expressions cannot safely reverse back into `users.name` without explicit support.

If writable lineage requires primary-key fields that the user did not select publicly, the ORM/query pipeline may use existing implicit selection mechanisms instead of exposing those fields in the final result shape.

Hidden required fields must not appear in the final mapped representation unless explicitly selected or mapped.

Relation loading must keep using `RelationRef` and `select()` / relation branch configuration. Do not add `with()`.

Relations remain class-based and pluggable. Relation definitions remain the source of relation intent; ORM write behavior is implemented by relation persistence planners that interpret those definitions.

## Schema Metadata

Do not create a heavy `EntityMetadataRegistry`. Avoid duplicated metadata that redefines fields, field types, relations, storage names, or primary keys already known by collection definitions.

Use this terminology:

```text
RepresentationMap
RepresentationSchema
RepresentationState
```

`RepresentationSchema` is the reusable mapping shape. It is not limited to instance-level tracking and can represent:

```text
root representation schema
child representation schema/template
relation item schema/template
```

Use this same concept for child and relation item mapping until implementation proves a separate `ChildRepresentationTemplate` class is necessary.

The distinction is:

```text
RepresentationSchema
    reusable mapping shape

RepresentationState
    concrete object + field/relation state items + baseline record revisions
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

Do not allow unsafe partial mutable managed entities.

- partial arrays are projections;
- partial DTOs are projections unless writable lineage exists for selected direct fields;
- partial `stdClass` can be writable only for fields with known lineage and identity;
- missing fields are not overwritten;
- expressions are read-only by default.

Partial explicit selections must not overwrite missing fields during sync. A fully default-selected root representation can be treated as the normal writable root case. Partial class representations should be treated carefully as projection-like unless the ORM explicitly tracks selected fields field-by-field.

## Lazy Loading

Lazy loading is dangerous and is not implemented in v1.0. The documented default is:

```php
LazyLoadingPolicy::PREVENT
```

If lazy loading is supported later, it must be explicit and disableable. Accessing an unloaded relation in strict/default mode should throw.

## Cascades

Do not create separate ORM cascade metadata. Cascade data already exists on relation definitions. The ORM write planner should interpret relation cascade settings later.

Guardrails:

- unloaded collections are not empty;
- orphan removal requires a known removed child or a fully loaded collection;
- cascade remove must not accidentally load/delete huge graphs;
- relation write behavior belongs to relation write planners;
- relation definitions remain the source of cascade intent;
- if boolean cascade becomes too limited, expand relation definition cascade semantics later.

## Relation State

`ToManyRelationState` owns relation deltas and knowledge completeness for to-many relations. Its load knowledge is internal to the runtime state:

```text
unloaded
partial
full
```
```

Rules:

- adding a child to an unloaded collection is valid;
- removing one known child from an unloaded collection can be valid;
- replacing an unloaded collection must be rejected or represented as an explicit full replacement;
- an unloaded collection must never be treated as empty.

Mutable relation collections must be ORM-owned. Plain arrays cannot safely represent writable relation mutations because they cannot notify the ORM and cannot distinguish:

```text
unloaded
partially loaded
fully loaded
loaded empty
full replacement
local projection change
```

Plain arrays may still be read/projection values, but they are not writable relation trackers. Do not support this as a persistence operation:

```php
$user->posts[] = $post;
```

Writable relation add/remove must go through an ORM-owned relation collection:

```php
$user->posts->add($post);
$user->posts->remove($post);
```

or a future explicit EntityManager API:

```php
$em->add($user, 'posts', $post);
$em->removeFrom($user, 'posts', $post);
```

The intended object concept is `ToManyRelationState`.

It should eventually own:

```text
owner record
relation definition/ref
loaded state
added children
removed children
child RepresentationSchema
backing collection
```

When a new child is added, the relation collection should apply the child `RepresentationSchema` for that relation item.

Do not hard-depend on Doctrine Collections, Illuminate Collections, Loophp Collections, or any other collection package in the ORM foundation. Relation tracking is ORM-specific because the ORM needs owner record, relation definition, loaded state, child schema, added/removed children, cascade/orphan behavior, and relation persistence planning. Generic collection libraries do not know those things.

Later, allow backing collection adapters/factories, similar to Cycle's collection factory approach. A third-party collection can be backing storage or an exposed collection API, but it cannot own ORM relation semantics.

## Future Namespaces

Likely future production namespaces:

```text
ON\Data\ORM
ON\Data\ORM\State
ON\Data\ORM\Sync
ON\Data\ORM\Persistence
ON\Data\ORM\Relation
ON\Data\ORM\Exception
```

These namespaces ship in v1.0.

## Phase 1A State Primitives

Phase 1A introduces the first production ORM state primitives only:

- `RecordLifecycle`
- `RecordHistory`
- `RecordState`
- `RecordStateStore`
- `RepresentationFieldSchema`
- `RepresentationSchema`
- `RepresentationState`
- `RepresentationStateStore`
- `SyncConflict`
- `SyncConflictDetector`

Phase 1A did not introduce `EntityQuery`, `with()`, repositories, lazy loading, `sync()` runtime, `flush()` runtime, write planning, SQL commands, or a public `persist()` API. Later Phase 2 work added scalar sync, flush orchestration, and neutral scalar persistence commands; see [`persistence.md`](./persistence.md).

## Phase 1D Relation Collection Primitives

Phase 1D introduces `ON\Data\ORM\Relation\ToManyRelationState` as the ORM-owned relation collection primitive. Plain arrays remain valid read/projection values, but they are not writable relation persistence trackers because they cannot own loaded state or add/remove intent.

`ToManyRelationState` owns the owner `RecordState`, relation name, loaded state, known in-memory items, local added/removed child intent, and the reusable child `RepresentationSchema` template. Known items are only the in-memory view currently held by the collection; they are not necessarily the full database relation. `isEmptyKnown()` means no items are currently known in memory, not that the database relation is empty. The collection stores the child schema for later relation runtime work, but it does not mutate that reusable template, apply the schema, or register tracked child representations in this phase.

Adding a child to an unloaded collection is allowed and makes the collection partially loaded because at least one item is now known, while the complete database set remains unknown. Removing one known object reference from an unloaded collection is also allowed and does not imply the relation is empty. Removing from a relation collection removes the relation link intent; it is not necessarily deletion of the child entity.

Future ORM runtime will apply the child schema to child `RecordState` instances and register tracked child representations. Third-party collection libraries may later provide backing adapters, but they do not own ORM relation semantics.

## Phase 1E Child Representation Adoption

Phase 1E introduces `Session::adopt()` and `Session::adoptRecord()` as the small bridge between reusable child schema templates and concrete ORM tracking.

`Session::adoptRecord()` attaches a reusable `RepresentationSchema` template to a concrete child `RecordState`, registers that record in `RecordStateStore`, captures baseline record revisions in `RepresentationFieldStateItem` entries, and registers the child object as a `RepresentationState` in `RepresentationStateStore`. Future relation runtime can use a `ToManyRelationState` child's schema with `Session::adoptRecord()` around flows such as adding a post object to a user's `ToManyRelationState`.

`ToManyRelationState` still owns relation add/remove intent. Adoption only tracks the child representation; it does not add the item to the relation collection, inspect relation loaded state, sync representation values, persist, flush, write SQL, or mutate the child schema template.

## Phase 1F Representation Value Reading

Phase 1F introduces `ON\Data\ORM\Representation\Sync\RepresentationReader` as the small service that reads current values from object and `stdClass` representations using `RepresentationSchema` paths.

The reader distinguishes a missing public property from a present `null` value, preserves schema insertion order, and supports simple dot paths through public properties. Numeric path segments can read array offsets for straightforward cases such as `posts.0.title`.

It only reads current representation values. It does not sync values into `RecordState`, convert values, mutate representations, persist, flush, call getters/setters, inspect private properties, or write SQL. Future mapper/property-access integration can expand this beyond public properties and simple paths.

## Phase 1G Sync Planning

Phase 1G introduces `SyncPlan` and `SyncFieldUpdate` as the planning layer between tracked representations and sync application. Scalar sync planning and apply now live in `ScalarRepresentationSynchronizer`.

`ScalarRepresentationSynchronizer` reads current representation values through `RepresentationReader`, detects conflicts, and produces a `SyncPlan` containing path-specific conflicts plus planned field updates. Read-only schemas are ignored for updates, conflicted paths are not planned as updates, and duplicate target updates with conflicting values are rejected instead of implying last-write-wins.

Different fields on the same `RecordState` remain separate `SyncFieldUpdate` entries. Later scalar sync and flush runtime aggregate them through the dirty `RecordState`; current relation persistence planning is described in [`persistence.md`](./persistence.md).

Planning does not mutate `RecordState`, update tracked baseline revisions, convert values, persist, flush, or apply the updates. Applying a `SyncPlan` is handled by the Phase 2 scalar synchronization runtime described in [`persistence.md`](./persistence.md). This completes the Phase 1 state/sync foundation.

## Phase 1 Completed Foundation

Phase 1 introduced only in-memory state, representation tracking, relation intent tracking, value reading, conflict detection, and sync-planning primitives. It did not introduce public `sync()`, `flush()`, `EntityManager`, query hydration runtime, repositories, database writes, relation write planners, or lazy loading. Later Phase 2 work added scalar sync/flush services and scalar database writes without adding an `EntityManager`, `UnitOfWork`, repositories, or lazy loading. Phase 3 adds relation representation sync and relation persistence planning; see [`persistence.md`](./persistence.md).

`RecordState` is the canonical aggregation point for synchronized changes. `ScalarRepresentationSynchronizer` produces field-level `SyncFieldUpdate` objects in a `SyncPlan` and applies them to `RecordState`; it does not group database commands or clear conflicts by itself. Multiple different fields on the same `RecordState` may appear as separate `SyncFieldUpdate` entries. Scalar sync rejects duplicate updates when the same concrete record target and same field receive conflicting values in the same plan.

Scalar sync-apply logic applies planned field updates to `RecordState`. Scalar flush/write planning aggregates dirty values from `RecordState::getDirtyValues()` into database commands, such as one update per record when possible.

```text
rep1 changes users.name
rep2 changes users.email
rep3 changes users.status

Each representation can produce separate sync updates.

After scalar sync apply:
    one RecordState has dirty values:
        name
        email
        status

Scalar flush can group those dirty values into one database update for the users record.
```

`ToManyRelationState` tracks relation add/remove intent only. It does not persist, adopt, or write relations. `Session::adopt()` adopts tracking only; it does not sync values. `RepresentationReader` reads current values only; it does not convert, mutate, or sync. `SyncPlan` is a description of conflicts and possible field updates, not an apply operation.

## Remaining Non-Goals

Do not introduce as part of the current ORM persistence layer:

- EntityManager;
- UnitOfWork;
- lazy loading;
- generated repositories;
- entity metadata registry;
- service container;
- event system;
- proxy objects;
- a separate transaction API on `Session`;
- stale-row detection;
- full database-default refresh.
