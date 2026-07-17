# ORM Representation Schema

`RepresentationSchema` describes persistence provenance for a representation shape. It is separate from the definition tree, the query selection graph, mapper hydration, tracked object state, and runtime relation state.

The current model is recursive: a schema can be the root representation shape, or it can be the related branch stored by a relation schema.

## Model Boundaries

### Definition Tree

The definition tree describes what can exist:

- collections
- fields
- relations
- keys
- table and column metadata

Definitions are metadata. They do not say which fields were selected for one result, which representation paths were hydrated, or which PHP object instance is currently tracked.

### Query Graph / Selection Graph

The query graph describes what was requested or read:

- selected fields
- aliases
- expressions
- relation selections
- future load state and completeness facts

The query graph is read-side intent and result-shaping information. It may later compile into representation schemas, but it is not itself the ORM persistence provenance model.

### `map($source)->to(...)`

`map($source)->to(...)` converts a data shape into an object shape. It hydrates arrays, `stdClass`, DTOs, and entity-like classes according to mapper rules.

The mapper does not by itself know persistence provenance. It should not become the schema declaration API. Mapper metadata such as source/target names can help shape values, but persistence also needs collection, field, identity, writability, relation provenance, and loaded relation state.

### RepresentationSchema

`RepresentationSchema` describes what a representation object shape means for ORM provenance.

It is the structure-only persistence provenance graph for one representation shape. It can be used as a root schema or as a related schema branch. It stores collection, field, relation, path, source-path, writability, and related-branch metadata. It does not store `RecordState`, `RecordFieldRef`, or `RecordRelationRef`.

It owns two path maps:

- field schemas
- relation schemas

A path can exist in only one of those maps. Scalar representation sync reads only field schemas. Relation representation sync reads only relation schemas.

### RepresentationState

`RepresentationState` describes concrete runtime attachment for one object instance. The object itself is held as the weak key in `RepresentationStateStore`, not inside `RepresentationState`.

It stores:

- the `RepresentationSchema`
- `RepresentationFieldStateItem` entries that attach field schemas to concrete `RecordState` objects and baseline record revisions
- `RepresentationRelationStateItem` entries that attach relation schemas to concrete owner `RecordState` objects

It is not a schema template. Multiple object instances may share the same reusable representation schema shape, while each representation state stores instance-specific runtime attachments and baseline revisions.

### ToManyRelationState / ToOneRelationState

`ToManyRelationState` is runtime relation state for one owner object. It tracks known, added, and removed collection items plus the collection load state. `ToOneRelationState` is the singular-relation runtime state and tracks the current target plus local change intent.

Runtime relation state is not representation shape. A `RepresentationRelationSchema` says that a representation path is a relation and stores the reusable related schema branch. A `ToManyRelationState` or `ToOneRelationState` says what one owner currently knows and intends to add, remove, or set at runtime.

Relation representation sync connects the two models:

- `MANY` relation schemas sync representation paths into `ToManyRelationState` instances.
- `ONE` relation schemas sync representation paths into `ToOneRelationState` instances.

In strict sync paths, related objects found at those representation paths must already be tracked/adopted. Relation representation sync validates that each `MANY` item and each non-null `ONE` target has a tracked representation, and throws `SyncException` with the relation path when it does not. It does not auto-adopt related objects by itself.

`Session::sync($representation, $schema)` is the explicit graph entry point for an untracked plain root object. The root schema is required for now; there is no class-to-schema inference. For an untracked root, the schema must be entity-shaped and target exactly one root collection through field schemas and/or relation owner schemas. Empty schemas and mixed/projection schemas spanning multiple root collections cannot create a new root `RecordState` and raise `StateException`. `Session::sync($representation)` can be used when the root object is already tracked. In both cases, the graph walk follows explicit `RepresentationRelationSchema` entries only. A `MANY` value may be `null` or an iterable of objects. A `ONE` value may be `null` or an object. Discovered untracked related objects are tracked with the relation schema's `getRelatedSchema()`, which is then applied to a new related `RecordState` using the existing representation adoption path. Primary-key presence is not lifecycle intent: newly discovered untracked objects (roots and related) default to `NEW`, even with a complete application-assigned primary key. Use `Session::update($object)` when a real object should be treated as an existing row during graph sync. Use `Session::identify($collection, $key)` for key-only existing references. Upsert is not implicit. Already-tracked objects are not duplicated and keep their current lifecycle. The walker guards object identity cycles and still walks an already-tracked object once if it has not been visited.

Graph sync does not infer relations from collection definitions, object properties, mapper metadata, or query selections. It syncs scalar and relation runtime state, but does not plan relation persistence, flush records, execute commands, or clear relation changes. Calling `sync($object)` again refreshes state and can bring newly attached related plain objects into the session. Query/projection/mixed schemas remain valid provenance for already-tracked or query-created representations; they require existing tracked state rather than creating a new root record.

## Flat projection adoption

Object graphs and flat writable projections use different adoption paths:

- Graph-shaped objects are adopted internally by `Session::sync($object, $schema)` / `Session::sync($object)`: one representation object resolves to nested related objects, each with its own schema branch.
- `QueryRepresentationStateBuilder` handles flat writable projection rows: one representation object can be backed by multiple `RecordState` objects through compiled `RepresentationSource` entries.

For flat projections, the compiler may add hidden identity selections tagged `SelectionTag::INTERNAL`. `QueryRepresentationIdentityColumns` maps source-path primary-key fields to result row keys, allowing adoption to read identity values and resolve `RecordState` keys. Internal selections are stripped from public query results, but they are required for writable flat projection tracking.

Flat projection adoption is used by writable `stdClass` query export and by `Session::update`/`create` with `SelectQuery::projection()`. See [`session-save-api.md`](./session-save-api.md).

Inbound flat binds resolve `RecordState` per source via `ProjectionRepresentationStateBuilder` (keys from the DTO / flat ops, or `RecordState::new` for create). Relation add for flat `create('posts')` registers intent on `ToManyRelationState` / `ToOneRelationState`.

Relation persistence planning then consumes changed `ToManyRelationState` and `ToOneRelationState` instances. Built-in planners cover many-to-many, has-many, belongs-to, and has-one relation definitions. `FirstOfMany` has no persistence planner by default — it is a read-only ordered view over has-many.

## Schema Kinds

### Field Schema

A field schema maps a representation path to a collection field plus a `sourcePath`.

```text
object.id          -> RepresentationFieldSchema(path: id, collection: users, field: id, sourcePath: [])
object.companyName -> RepresentationFieldSchema(path: companyName, collection: companies, field: name, sourcePath: [company])
```

`RepresentationFieldSchema` is structural only. Writable field schemas can be synchronized back into the concrete `RecordState` named by the corresponding `RepresentationFieldStateItem`. Read-only field schemas remain scalar field provenance, but scalar sync ignores them for updates.

### Relation Schema

A relation schema maps one representation path to an owner collection, relation name, and one reusable related schema.

```text
object.posts -> RepresentationRelationSchema(path: posts, owner: users, relation: posts) MANY
    related schema:
        object.posts[].id    -> posts.id
        object.posts[].title -> posts.title
```

A relation path such as `posts` does not merely mean a relation named `posts`. It means the specific relation carried by `RepresentationRelationSchema`, including the owning collection. Concrete runtime ownership lives in `RepresentationRelationStateItem`. This keeps aliases and mixed representations safe:

```text
object.name  -> RepresentationFieldSchema(collection: companies, field: name, sourcePath: [company])
object.posts -> RepresentationRelationSchema(owner: users, relation: posts)
```

For a `MANY` relation, `getRelatedSchema()` is the item shape. The same related schema is reused for every child object in that representation shape. Do not create one schema object per child instance.

```text
users representation schema
    relation posts MANY
        getRelatedSchema() -> post item schema

post object A uses the same item schema shape as post object B
```

For a `ONE` relation, `getRelatedSchema()` is the target shape.

```text
object.author -> relation posts.author ONE
    related schema:
        object.author.id   -> users.id
        object.author.name -> users.name
```

Use `getRelatedSchema()` for both cardinalities. Do not introduce separate `getItemBinding()` or `getTargetSchema()` names.

## Attaching Schemas

`RepresentationSchema` stays structure-only. It is attached to concrete runtime state by services that create `RepresentationState` items:

- `RepresentationState::fromRecords()` builds field and relation state items from a schema and concrete `RecordState` instances keyed by source path.
- `QueryRepresentationStateBuilder` consumes compiled `RepresentationSource` entries, resolves flat projection identities through `QueryRepresentationIdentityColumns`, and builds field state items against concrete source records.
- Session save-API flat binds attach field sources to concrete records via `ProjectionRepresentationStateBuilder`.

These attachment steps do not mutate the reusable schema shape. `Session::sync($object, $schema)` is the explicit API that chooses related objects from relation path values, then uses each relation schema's `getRelatedSchema()` with the existing adoption path. Schema attachment still does not infer relations from objects or plan relation persistence.

## Current Sync Boundaries

Scalar representation sync uses field schemas only:

```text
RepresentationSchema              -> getWritableFieldSchemas()
RepresentationReader               -> readPath()
SyncConflictDetector                 -> detect()
ScalarRepresentationSynchronizer     -> buildPlan() / applyPlan()
```

Relation representation sync uses relation schemas only:

```text
RepresentationReader              -> readItems() / readTarget()
RelationRepresentationSynchronizer -> ToManyRelationState / ToOneRelationState
RepresentationStateResolver      -> already-tracked related objects only
```

`MANY` schemas become `ToManyRelationState` runtime state. `ONE` schemas become `ToOneRelationState` runtime state. `Session::sync($object, $schema)` exposes this graph-aware representation sync step directly for plain objects with a single-collection root schema, and `Session::sync($object)` refreshes an already-tracked object graph. `Session::flush()` still runs strict sync automatically before planning and flushing.

Normal graph and query-created schemas remain strict: missing writable scalar paths or malformed relation values still raise through sync. Flat save-API overlays follow the same scalar sync rules after adoption.

`Session::flush()` does not adopt newly attached untracked objects. If a new related plain object is attached after the last explicit `sync($object)`, `flush()` raises through the strict relation synchronization path. Call `sync($object)` again to refresh runtime state before flushing.

## Non-Goals

The recursive schema model does not implement:

- automatic relation graph inference
- automatic child adoption from flush
- array root input for sync
- cascade persistence policy
- orphan removal
- relation inference from developer objects
- SQL generation
- transactions
- dependency ordering for generated ids
- Registry awareness of ORM persistence
- a separate `BindingGraph`, `BindingNode`, `PersistenceGraph`, `QueryGraph`, or `BindingTree`

Keep `RepresentationSchema` as the graph/branch class.
