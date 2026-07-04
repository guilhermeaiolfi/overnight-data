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

A path can exist in only one of those maps. Scalar sync currently reads only field bindings. Expression and relation bindings are modeled now so later work can reason about them without changing the shape of `RepresentationBinding`.

### TrackedRepresentation

`TrackedRepresentation` describes one object instance.

It stores:

- the object
- the `RepresentationBinding`
- baseline record revisions

It is not a binding template. Multiple object instances may share the same reusable representation binding shape, while each tracked representation stores instance-specific baseline revisions.

### RelatedCollection / Future RelatedReference

`RelatedCollection` is runtime relation state for one owner object. It tracks known, added, and removed collection items plus the collection load state. A future `RelatedReference` should do the same kind of job for singular runtime relations.

Runtime relation state is not representation shape. A `RepresentationRelationBinding` says that a representation path is a relation and stores the reusable related binding branch. A `RelatedCollection` says what one owner currently knows and intends to add or remove at runtime.

## Binding Kinds

### Field Binding

A field binding maps a representation path to a record field reference.

```text
object.id   -> users.id
object.name -> users.name
```

Writable field bindings can be synchronized back into `RecordState`. Read-only field bindings remain scalar field provenance, but scalar sync ignores them for updates.

### Expression Binding

An expression binding represents a selected value that is not writable by default.

```text
object.postCount -> count(posts.id) as post_count
```

Expression bindings can model aliases, aggregates, computed values, or query expressions. They do not store query expression objects yet, and scalar sync does not write them back.

### Relation Binding

A relation binding maps one representation path to one reusable related binding.

```text
object.posts -> relation users.posts MANY
    related binding:
        object.posts[].id    -> posts.id
        object.posts[].title -> posts.title
```

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

`RepresentationBinding::applyToRecordState($state)` currently applies only field bindings:

- template field refs for the state's collection become concrete state-targeted refs
- concrete field refs are rejected
- mismatched template collections are rejected
- expression bindings are copied unchanged
- relation bindings are copied unchanged

It does not recursively apply related bindings. It does not infer relations from objects. It does not adopt child objects. It does not plan relation persistence.

## Current Scalar Sync Boundary

Scalar sync uses field bindings only:

```text
RepresentationValueReader -> getFields()
SyncConflictDetector      -> getWritableFieldBindings()
SyncPlanner               -> getWritableFieldBindings()
```

Expression bindings and relation bindings are ignored by scalar sync for now. They should survive on the binding model as provenance for later tasks.

## Non-Goals

The recursive binding model does not implement:

- relation graph sync
- automatic child adoption
- relation inference from developer objects
- `BelongsTo` or `HasOne` persistence planners
- SQL generation
- transactions
- dependency ordering for generated ids
- Registry awareness of ORM persistence
- a separate `BindingGraph`, `BindingNode`, `PersistenceGraph`, `QueryGraph`, or `BindingTree`

Keep `RepresentationBinding` as the graph/branch class.
