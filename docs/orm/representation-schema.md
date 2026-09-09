# ORM Representation Schema

Canonical spec for `RepresentationSchema`: durable **path + provenance** for a representation shape.

Related history (do not treat as competing specs):

- [`../architecture/decisions/0002-fetch-loadbranch-vs-place-schema.md`](../architecture/decisions/0002-fetch-loadbranch-vs-place-schema.md) — accepted: fetch (`LoadBranch`) vs schema paths
- [`../architecture/proposals/0001-representation-schema-as-reusable-model.md`](../architecture/proposals/0001-representation-schema-as-reusable-model.md) — reusable shape / `query($schema)` reopen (schema landed; reopen still open)
- [`../architecture/proposals/archive/0002-recursive-projection-levels.md`](../architecture/proposals/archive/0002-recursive-projection-levels.md) — archived: root/nested selection parity
- [`../architecture/proposals/archive/0003-load-graph-and-schema-as-place.md`](../architecture/proposals/archive/0003-load-graph-and-schema-as-place.md) — archived implementation history for the fetch/path split

This document is the living model. Decisions and open proposals above are background.

---

## What it is

`RepresentationSchema` is the structure-only graph for one representation shape (root or nested related branch). It answers:

1. **Path** — which keys appear on the object/array, in order  
2. **Provenance** — how those paths map to collections/fields (and nested related schemas) for Session sync/adoption  

It is separate from:

| Concern | Owner |
|---|---|
| Table/column/relation metadata | Definition `Collection` |
| Authoring + fetch (SQL, JOIN/SEPARATE, INTERNAL/SQL_ONLY) | `SelectQuery` / `SelectionList` / `LoadBranch` |
| Instance tracking | `RepresentationState` |
| Hydration naming only | Mapper |

Query may import `RepresentationSchema` as the intentional path boundary (no separate `ProjectionLayout`). Fetch tags stay on Query.

The necessary path vs fetch split (read steps R2–R3, write step W2) is in [`../read-write.md`](../read-write.md).

---

## Path as index (spine)

Every entry is keyed by a **path** local to this schema level (not a dotted root→leaf string). Nested levels use nested schemas.

```text
RepresentationSchema
  collection
  fields[path]       → RepresentationFieldSchema
  relations[path]    → RepresentationRelationSchema → nested RepresentationSchema
  expressions[path]  → RepresentationExpressionSchema
  paths[]            → ordered union of all paths (collision domain)
```

### Rules

| Rule | Detail |
|---|---|
| Path = result key | e.g. `name`, `posts`, `lineTotal`, `authorName` — property/key on this level’s payload |
| Uniqueness | A path exists in **at most one** of fields / relations / expressions |
| Order | `paths[]` preserves insertion / compile order |
| Flat related data | Path is the alias (`authorName`); provenance uses `sourcePath` (e.g. `['author']`) — **path index ≠ source path** |
| Nested data | Relation path `posts` → `getRelatedSchema()`; child fields are paths on that nested schema (`title`), not `posts.title` on the parent |
| `star` / `all()` | **Expand** to concrete Public field paths at compile — never store `"*"` as a path |
| Expressions | Path **requires** `->as('…')`. No library default alias for now; unaliased expressions are not schema paths |
| Aggregates like `count(*)` | Same: public path is the alias we assign in the library API, not a DB-invented label |

### Path filters (direction)

Keep `getPaths()` as the **full ordered universe** (collision + inspection of everything on the schema, including Implicit).

Prefer **filter helpers** (SelectionList-style), not overloading `getPaths()`:

| Helper | Meaning |
|---|---|
| `getPaths()` | All paths (fields + relations + expressions), ordered |
| `getPublicScalarPaths()` | Public fields + expressions (assemble), path order |
| `getImplicitScalarPaths()` | Implicit field paths (PK backfill), field order |
| `getFields()` / `getRelations()` / `getExpressions()` | By kind |
| `getPublicFields()` / `getImplicitFields()` / `getFieldsByRole()` | Fields by `RepresentationFieldRole` |
| `getFieldPathsByRole()` | Field paths by role (no expressions / relations) |
| `filterFields($predicate)` | Custom field subset (`getByTag`-style) |
| `getWritableFieldSchemas()` / `getReadOnlyFieldSchemas()` | By writability |
| `getFlatFieldPaths()` | Public fields with non-empty `sourcePath` |

Do **not** make `getPaths()` mean “public only”; that loses the Implicit/collision universe. Call sites should use the helpers above instead of looping `getFields()` / `getPaths()` and checking `isPublic()` / `isImplicit()`.

---

## Field roles (`RepresentationFieldRole`)

Converges durable selection **meaning**, not Query fetch tags.

| Role | Meaning | Public path? |
|---|---|---|
| **Public** | Authored / user-facing path | Yes |
| **Implicit** | Not authored path; on schema for adoption/tracking (e.g. PK backfill) | No |

- Selected `$q->id` → **Public** (even though it is a PK)  
- Compiler PK backfill when `id` was not selected → **Implicit**  
- Do **not** infer role from “is in collection primary key” alone  

Expressions have no role enum: they are always non-writable paths (public scalars when present). Sync ignores expression paths.

`skipWhenMissing` is sync/adoption **source-record** presence, not a substitute for Public vs Implicit, and not Session PATCH. Own-level fields (`sourcePath []`) require the source record when attaching state. Fields with a related `sourcePath` skip when that related record is absent (LEFT JOIN / null belongs-to). Override with `withSkipWhenMissing()`. Omitted properties on a DTO are a separate `update` PATCH rule — see [`session-save-api.md`](./session-save-api.md).

---

## Schema kinds

### Field

Maps a path → collection field + `sourcePath` + writability + role.

```text
id          → users.id, sourcePath [], Public or Implicit
companyName → companies.name, sourcePath [company], Public
```

### Relation

Maps a path → owner collection, relation name, nested `RepresentationSchema`, load knowledge.

```text
posts → users.posts MANY → related schema (post item shape)
```

### Expression

Maps a path → `ValueExpressionInterface` (aliased). Always read-only for Session.

```text
lineTotal → expression AST, path lineTotal
```

---

## Attachment (one walk)

`RepresentationAdoptionEngine::attach()` binds **every source** on this schema (`getSources()`), then walks **relation children** (`getRelatedSchema()`). Root-only, loaded `posts`, and flat `authorName` are the same walk. Nested objects are separate representations; flat related fields are extra records on this representation.

Policy (`Hydrate` / `Identify` / `Patch` / `Create`) is the operation, not a layout choice. Writable query hydrate reads the identity overlay (`sourceRow`) for hidden source ids (`company.id`); `Session::sync()` still reads the live object. Hydrate constructs loaded relation state from the walked children (`ToManyRelationState::full` / `::partial`, `ToOneRelationState`), the same way it constructs `RecordState::clean` for rows. Writable `track()` does not call `sync()` afterward. If the fetched overlay has no identity, tracking throws (`writable()` does not invent primary-key columns, and results without identity are never tracked).

Writable prepare builds `QuerySourceIdentities` from schema sources (Implicit PK paths such as `company.id`). It does not add Query `INTERNAL` selections.

---

## Query compile and assemble

### Compile (`SelectQuery::projection()` / fetch schema)

- FieldRefs → Public field schemas (aliases as paths; flats get `sourcePath`)  
- Aliased non-field selections → expression schemas  
- Relation selections → relation schemas (recursive)  
- Missing PKs for each source → Implicit field schemas  
- Star/default → expand to Public fields  

### Assemble (path-first)

**Schema is the path base.** When `SelectQuery::needsRowAssemble()` is true
(aliases, flats, or relation loads), `beginFetch()` compiles a
`RepresentationSchema` via `projection()` and `RelationOutputProcessor` writes
public scalars via `getPublicScalarPaths()` and identity overlay keys via
`getImplicitScalarPaths()` plus each source's `getPrimaryKeyPaths()`.
Writable `prepare()` uses the same `projection()` and reuses that schema.
`LoadFieldPlanner` requires the compiled schema and binds SQL from schema
scalar paths at each load level. Query `EXPLICIT` tags are fetch/authoring
only — not a path fallback. Join keys stay on loaders (`requireFields()`).
Hidden source primary keys are Implicit fields with source-qualified paths
(`company.id`) when they would collide with root `id`; they stay on the
identity overlay, not the public row.

Plain own-field reads skip assemble and skip schema compile (executor column
names already match paths).

Fetch tags (`COLUMN`, `SQL_ONLY`, `INTERNAL`, `DEFAULT`, …) remain Query/runtime only.

### `forPrimaryKey()`

Builds a schema whose Public paths are the primary-key fields. That schema **is** the representation (key-only object). Those paths are Public, not Implicit — Implicit is for backfill onto a larger shape.

---

## Model boundaries (unchanged intent)

### Definition tree

What can exist: collections, fields, relations, keys, storage names.

### Query graph

What was requested to read/fetch: selections, tags, load options, strategies. Compiles **into** representation schemas; is not itself the durable path model.

### Mapper

Shape conversion only; not persistence provenance.

---

## Runtime: state, sync, adoption

### RepresentationState

Attaches a schema to one object instance: field/relation state items + baselines. Multiple instances may share one schema.

### Sync

- Scalar sync: writable **field** schemas (via state items)  
- Relation sync: relation schemas only  
- Expression paths: ignored  

`Session::sync($object, $schema)` walks the schema once (sources, then relations). Untracked roots still need a schema.

### Query hydrate

`RepresentationAdoptionEngine::attach()` with `AdoptionPolicy::Hydrate` and the identity overlay:

- Bind `getSources()` (including flat related records) from `sourceRow` + the object  
- Walk relation branches via `getRelatedSchema()`  

See [`session-save-api.md`](./session-save-api.md) and [`writable-select-query-projections.md`](./writable-select-query-projections.md).

---

## Open / next (not blocking this spec)

- `query($schema)` reopen (0001 remainder) 

---

## Non-goals

- Automatic relation inference from objects/mapper  
- Array root sync  
- Cascade / orphan policies in the schema type  
- SQL generation or transactions on the schema  
- A parallel `BindingTree` / `QueryPlan` type beside `RepresentationSchema`  
- Storing `"*"` or DB-default expression labels as paths  
- Sharing `SelectionList` / fetch tags as the schema store  

Keep `RepresentationSchema` as the graph/branch class; path is the index.
