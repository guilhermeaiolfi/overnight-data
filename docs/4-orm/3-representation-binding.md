# ORM Representation Binding

`RepresentationBinding` describes persistence provenance for a representation shape. It is separate from the definition tree, the query selection graph, mapper hydration, tracked object state, and runtime relation state.

The current model is recursive: a binding can be the root representation shape, or it can be the related branch stored by a relation binding.

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

The query graph is read-side intent and result-shaping information. It may later compile into representation bindings, but it is not itself the ORM persistence provenance model.

### `map($source)->to(...)`

`map($source)->to(...)` converts a data shape into an object shape. It hydrates arrays, `stdClass`, DTOs, and entity-like classes according to mapper rules.

The mapper does not by itself know persistence provenance. It should not become the binding declaration API. Mapper metadata such as source/target names can help shape values, but persistence also needs collection, field, identity, writability, expressions, relation provenance, and loaded relation state.

### RepresentationBinding

`RepresentationBinding` describes what a representation object shape means for ORM provenance.

It is the persistence provenance graph for one representation shape. It can be used as a root binding or as a related binding branch. It owns three path maps:

- field bindings
- expression bindings
- relation bindings

A path can exist in only one of those maps. Scalar representation sync reads only field bindings. Relation representation sync reads only relation bindings. Expression bindings are modeled now so later work can reason about them without changing the shape of `RepresentationBinding`.

### TrackedRepresentation

`TrackedRepresentation` describes one object instance.

It stores:

- the object
- the `RepresentationBinding`
- baseline record revisions

It is not a binding template. Multiple object instances may share the same reusable representation binding shape, while each tracked representation stores instance-specific baseline revisions.

### RelatedCollection / RelatedReference

`RelatedCollection` is runtime relation state for one owner object. It tracks known, added, and removed collection items plus the collection load state. `RelatedReference` is the singular-relation runtime state and tracks the current target plus local change intent.

Runtime relation state is not representation shape. A `RepresentationRelationBinding` says that a representation path is a relation and stores the reusable related binding branch. A `RelatedCollection` or `RelatedReference` says what one owner currently knows and intends to add, remove, or set at runtime.

Relation representation sync connects the two models:

- `MANY` relation bindings sync representation paths into `RelatedCollection` instances.
- `ONE` relation bindings sync representation paths into `RelatedReference` instances.

In strict sync paths, related objects found at those representation paths must already be tracked/adopted. Relation representation sync validates that each `MANY` item and each non-null `ONE` target has a tracked representation, and throws `SyncException` with the relation path when it does not. It does not auto-adopt related objects by itself.

`Session::sync($representation, $binding)` is the explicit graph entry point for an untracked plain root object. The root binding is required for now; there is no class-to-binding inference. `Session::sync($representation)` can be used when the root object is already tracked. In both cases, the graph walk follows explicit `RepresentationRelationBinding` entries only. A `MANY` value may be `null` or an iterable of objects. A `ONE` value may be `null` or an object. Discovered untracked related objects are tracked with the relation binding's `getRelatedBinding()`, which is then applied to a new related `RecordState` using the existing representation adoption path. Newly discovered related objects default to `NEW` and their new record state is initialized from bound field paths; already-tracked objects are not duplicated and keep their current lifecycle. The walker guards object identity cycles and still walks an already-tracked object once if it has not been visited.

Graph sync does not infer relations from collection definitions, object properties, mapper metadata, or query selections. It syncs scalar and relation runtime state, but does not plan relation persistence, flush records, execute commands, or clear relation changes. Calling `sync($object)` again refreshes state and can bring newly attached related plain objects into the session.

Relation persistence planning then consumes changed `RelatedCollection` and `RelatedReference` instances. Built-in planners cover many-to-many, has-many, belongs-to, and has-one relation definitions.

## Binding Kinds

### Field Binding

A field binding maps a representation path to a `RecordFieldRef`.

```text
object.id   -> users.id
object.name -> users.name
```

`RecordFieldRef` can be a collection-template ref or a concrete `RecordState` ref. Writable field bindings can be synchronized back into `RecordState`. Read-only field bindings remain scalar field provenance, but scalar sync ignores them for updates.

### Expression Binding

An expression binding represents a selected value that is not writable by default.

```text
object.postCount -> count(posts.id) as post_count
```

Expression bindings can model aliases, aggregates, computed values, or query expressions. They do not store query expression objects yet, and scalar sync does not write them back.

### Relation Binding

A relation binding maps one representation path to one owner-aware `RecordRelationRef` and one reusable related binding.

```text
object.posts -> RecordRelationRef(users.posts) MANY
    related binding:
        object.posts[].id    -> posts.id
        object.posts[].title -> posts.title
```

A relation path such as `posts` does not merely mean a relation named `posts`. It means the specific relation carried by `RecordRelationRef`, including the owning collection or record state. This keeps aliases and mixed representations safe:

```text
object.name  -> RecordFieldRef(companies.name)
object.posts -> RecordRelationRef(users.posts)
```

Like field refs, relation refs can start as collection-template refs and become concrete when a root binding is applied to a `RecordState`.

For a `MANY` relation, `getRelatedBinding()` is the item shape. The same related binding is reused for every child object in that representation shape. Do not create one binding object per child instance.

```text
users representation binding
    relation posts MANY
        getRelatedBinding() -> post item binding

post object A uses the same item binding shape as post object B
```

For a `ONE` relation, `getRelatedBinding()` is the target shape.

```text
object.author -> relation posts.author ONE
    related binding:
        object.author.id   -> users.id
        object.author.name -> users.name
```

Use `getRelatedBinding()` for both cardinalities. Do not introduce separate `getItemBinding()` or `getTargetBinding()` names.

## Applying Bindings

`RepresentationBinding::applyToRecordState($state)` applies root field and relation owner refs to the provided record state:

- template field refs for the state's collection become concrete state-targeted refs
- concrete field refs are rejected
- mismatched template collections are rejected
- expression bindings are copied unchanged
- template relation refs for the state's collection become concrete state-targeted refs
- concrete relation refs are rejected
- mismatched relation owner collections are rejected

It does not recursively apply related bindings by itself. `Session::sync($object, $binding)` is the explicit API that chooses related objects from relation path values, then uses each relation binding's `getRelatedBinding()` with the existing adoption path. Binding application still does not infer relations from objects or plan relation persistence.

## Current Sync Boundaries

Scalar representation sync uses field bindings only:

```text
RepresentationValueReader -> getFields()
SyncConflictDetector      -> getWritableFieldBindings()
SyncPlanner               -> getWritableFieldBindings()
```

Relation representation sync uses relation bindings only:

```text
RepresentationValueReader         -> getRelations()
RelationRepresentationSynchronizer -> RelatedCollection / RelatedReference
TrackedRepresentationResolver      -> already-tracked related objects only
```

`MANY` bindings become `RelatedCollection` runtime state. `ONE` bindings become `RelatedReference` runtime state. `Session::sync($object, $binding)` exposes this graph-aware representation sync step directly for plain objects with a root binding, and `Session::sync($object)` refreshes an already-tracked object graph. `Session::flush()` still runs strict sync automatically before planning and flushing. Expression bindings are ignored by both synchronizers for now. They should survive on the binding model as provenance for later tasks.

`Session::flush()` does not adopt newly attached untracked objects. If a new related plain object is attached after the last explicit `sync($object)`, `flush()` raises through the strict relation synchronization path. Call `sync($object)` again to refresh runtime state before flushing.

## Non-Goals

The recursive binding model does not implement:

- automatic relation graph inference
- automatic child adoption from flush
- cascade persistence policy
- orphan removal
- relation inference from developer objects
- SQL generation
- transactions
- dependency ordering for generated ids
- Registry awareness of ORM persistence
- a separate `BindingGraph`, `BindingNode`, `PersistenceGraph`, `QueryGraph`, or `BindingTree`

Keep `RepresentationBinding` as the graph/branch class.
