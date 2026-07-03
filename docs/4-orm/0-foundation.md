# ORM Foundation

Phase 0 records the architecture for the future `ON\Data` ORM. It does not introduce runtime ORM behavior, production state classes, write planning, lazy loading, generated repositories, service containers, events, or proxy objects.

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

## Representation

A representation is a PHP value that exposes data to the user. It may be a class object, `stdClass`, array projection, DTO, or a future dynamic model.

Representations are not automatically the source of truth. They can drift from the record state until explicitly synchronized.

## TrackedRepresentation

A `TrackedRepresentation` is concrete. It binds one PHP representation object/value to one or more record states through an applied `RepresentationBinding`.

It stores:

- the PHP representation object/value;
- the applied representation binding;
- baseline record revisions captured when it was created, attached, or last synced/refreshed;
- field lineage from representation paths/properties to record field references;
- writable/read-only flags per mapped slot;
- relation collection loaded state later.

It does not store baseline field values. When sync needs an old value, it asks the owning `RecordState` history for the value at the tracked baseline revision.

Do not use `TrackedRepresentation` as a template. It is only for a concrete representation object/value after a binding has been applied and baseline record revisions are known.

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

## State Maps

Avoid object-first identity-map terminology. The preferred model is:

```text
ON\Data\Key -> RecordState
Representation object id -> TrackedRepresentation
```

Classic ORMs usually map identity to entity object. This ORM maps identity to record state, then allows many representations over that state.

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

The first implementation can define root default fields as all normal root scalar fields. Later this may become smarter based on target representation requirements, but do not implement that in Phase 0.

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

Relations remain class-based and pluggable. Relation definitions remain the source of relation intent; ORM write behavior should be implemented later by relation write planners that interpret those definitions.

## Binding Metadata

Do not create a heavy `EntityMetadataRegistry`. Avoid duplicated metadata that redefines fields, field types, relations, storage names, or primary keys already known by collection definitions.

Use this terminology:

```text
RepresentationMap
RepresentationBinding
TrackedRepresentation
```

`RepresentationBinding` is the reusable mapping shape. It is not limited to instance-level tracking and can represent:

```text
root representation binding
child representation binding/template
relation item binding/template
```

Use this same concept for child and relation item mapping until implementation proves a separate `ChildRepresentationTemplate` class is necessary.

The distinction is:

```text
RepresentationBinding
    reusable mapping shape

TrackedRepresentation
    concrete object + applied binding + baseline record revisions
```

An applied binding may need local record state that is more precise than `RecordFieldRef` with an optional `Key`. A keyed field reference works for persisted records, and a keyless reference is useful for template-like shapes, but it is ambiguous for multiple new child records:

```text
posts[no-key].title
posts[no-key].title
```

Before relation collection writes are implemented, applied bindings may need to target an actual `RecordState` or local in-memory record handle, not only a persisted `Key`.

Sources of binding information:

1. Query selection lineage.
2. Collection/view definitions.
3. Mapper attributes such as `MapFrom` and `MapTo`.
4. Optional explicit binding for manually attached/new representations.

`MapFrom` and `MapTo` are naming hints. They are not enough for ORM persistence because the ORM also needs source collection, field lineage, identity, read/write status, and relation state.

## stdClass

`stdClass` is a first-class representation target.

A `stdClass` may be persistable if it has representation binding and lineage. A random `stdClass` without binding is only a projection unless explicitly attached with enough information.

## Partial Results

Do not allow unsafe partial mutable managed entities.

- partial arrays are projections;
- partial DTOs are projections unless writable lineage exists for selected direct fields;
- partial `stdClass` can be writable only for fields with known lineage and identity;
- missing fields are not overwritten;
- expressions are read-only by default.

Partial explicit selections must not overwrite missing fields during sync. A fully default-selected root representation can be treated as the normal writable root case. Partial class representations should be treated carefully as projection-like unless the ORM explicitly tracks selected fields field-by-field.

## Lazy Loading

Lazy loading is dangerous and is not implemented in Phase 0. The documented default is:

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

Future relation collection states:

```php
enum RelationCollectionState
{
    case UNLOADED;
    case PARTIALLY_LOADED;
    case FULLY_LOADED;
}
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

The intended object concept is `RelatedCollection` or, if the implementation prefers an internal name, `TrackedRelationCollection`.

It should eventually own:

```text
owner record
relation definition/ref
loaded state
added children
removed children
child RepresentationBinding
backing collection
```

When a new child is added, the relation collection should apply the child `RepresentationBinding` for that relation item.

Do not hard-depend on Doctrine Collections, Illuminate Collections, Loophp Collections, or any other collection package in the ORM foundation. Relation tracking is ORM-specific because the ORM needs owner record, relation definition, loaded state, child binding, added/removed children, cascade/orphan behavior, and future relation write planning. Generic collection libraries do not know those things.

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

Do not create them in Phase 0 unless a later test stub needs them.

## Phase 1A State Primitives

Phase 1A introduces the first production ORM state primitives only:

- `RecordLifecycle`
- `RecordHistory`
- `RecordState`
- `RecordStateMap`
- `RecordFieldRef`
- `RepresentationFieldBinding`
- `RepresentationBinding`
- `TrackedRepresentation`
- `TrackedRepresentationMap`
- `SyncConflict`
- `SyncConflictDetector`

It does not introduce `EntityQuery`, `with()`, repositories, lazy loading, `sync()` runtime, `flush()` runtime, write planning, SQL commands, or a public `persist()` API.

## Phase 0 Non-Goals

Do not implement:

- EntityManager runtime;
- UnitOfWork;
- RecordState runtime;
- sync logic;
- flush logic;
- write planner;
- SQL write commands;
- lazy loading;
- relation write planners;
- generated repositories;
- entity metadata registry;
- service container;
- event system;
- proxy objects.
