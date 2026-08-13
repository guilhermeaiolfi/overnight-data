# ORM Representation Schema

Canonical spec for `RepresentationSchema`: durable **place + provenance** for a representation shape.

Related history (do not treat as competing specs):

- [`../architecture/decisions/0002-fetch-loadbranch-vs-place-schema.md`](../architecture/decisions/0002-fetch-loadbranch-vs-place-schema.md) — accepted: fetch (`LoadBranch`) vs place (this schema)
- [`../architecture/proposals/0001-representation-schema-as-reusable-model.md`](../architecture/proposals/0001-representation-schema-as-reusable-model.md) — reusable shape / `query($schema)` reopen (partially landed; reopen still open)
- [`../architecture/proposals/0002-recursive-projection-levels.md`](../architecture/proposals/0002-recursive-projection-levels.md) — root/nested selection parity
- [`../architecture/proposals/archive/0003-load-graph-and-schema-as-place.md`](../architecture/proposals/archive/0003-load-graph-and-schema-as-place.md) — archived implementation history for the fetch/place split

This document is the living model. Decisions and open proposals above are background.

---

## What it is

`RepresentationSchema` is the structure-only graph for one representation shape (root or nested related branch). It answers:

1. **Place** — which paths appear on the object/array, in order  
2. **Provenance** — how those paths map to collections/fields (and nested related schemas) for Session sync/adoption  

It is separate from:

| Concern | Owner |
|---|---|
| Table/column/relation metadata | Definition `Collection` |
| Authoring + fetch (SQL, JOIN/SEPARATE, INTERNAL/SQL_ONLY) | `SelectQuery` / `SelectionList` / `LoadBranch` |
| Instance tracking | `RepresentationState` |
| Hydration naming only | Mapper |

Query may import `RepresentationSchema` as the intentional place boundary (no separate `ProjectionLayout`). Fetch tags stay on Query.

---

## Path as index (spine)

Every entry is keyed by a **place path** local to this schema level (not a dotted root→leaf string). Nested levels use nested schemas.

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
| Path = place name | e.g. `name`, `posts`, `lineTotal`, `authorName` — property/key on this level’s payload |
| Uniqueness | A path exists in **at most one** of fields / relations / expressions |
| Order | `paths[]` preserves insertion / compile order |
| Flat related data | Place path is the alias (`authorName`); provenance uses `sourcePath` (e.g. `['author']`) — **path index ≠ source path** |
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
| `getPublicScalarPaths()` | Public fields + expressions (assemble place), path order |
| `getImplicitScalarPaths()` | Implicit field paths (PK backfill), field order |
| `getFields()` / `getRelations()` / `getExpressions()` | By kind |
| `getPublicFields()` / `getImplicitFields()` / `getFieldsByRole()` | Fields by `RepresentationFieldRole` |
| `getFieldPathsByRole()` | Field paths by role (no expressions / relations) |
| `filterFields($predicate)` | Custom field subset (`getByTag`-style) |
| `getWritableFieldSchemas()` / `getReadOnlyFieldSchemas()` | By writability |
| `getFlatFieldPaths()` | Public fields with non-empty `sourcePath` |

Do **not** make `getPaths()` mean “public only”; that loses the Implicit/collision universe. Call sites should use the helpers above instead of looping `getFields()` / `getPaths()` and checking `isPublicPlace()` / `isImplicit()`.

---

## Field roles (`RepresentationFieldRole`)

Converges durable selection **meaning**, not Query fetch tags.

| Role | Meaning | Public place? |
|---|---|---|
| **Public** | Authored / user-facing place path | Yes |
| **Implicit** | Not authored place; on schema for adoption/tracking (e.g. PK backfill) | No |

- Selected `$q->id` → **Public** (even though it is a PK)  
- Compiler PK backfill when `id` was not selected → **Implicit**  
- Do **not** infer role from “is in collection primary key” alone  

Expressions have no role enum: they are always non-writable place (public scalars when present). Sync ignores expression paths.

`skipWhenMissing` is sync/adoption presence, not a substitute for Public vs Implicit. Own-level fields (`sourcePath []`) require the source record (and the object path on sync). Fields with a related `sourcePath` skip when that related record is absent (LEFT JOIN / null belongs-to). Override with `withSkipWhenMissing()`.

---

## Schema kinds

### Field

Maps a place path → collection field + `sourcePath` + writability + role.

```text
id          → users.id, sourcePath [], Public or Implicit
companyName → companies.name, sourcePath [company], Public
```

### Relation

Maps a place path → owner collection, relation name, nested `RepresentationSchema`, load knowledge.

```text
posts → users.posts MANY → related schema (post item shape)
```

### Expression

Maps a place path → `ValueExpressionInterface` (aliased). Always read-only for Session.

```text
lineTotal → expression AST, path lineTotal
```

---

## Attachment modes (one type)

| Mode | When | How |
|---|---|---|
| **Graph** | Nested `relations` present | Related objects under relation paths |
| **Flat** | Fields with non-empty `sourcePath` (often no relation containers) | Multiple `RecordState`s via `getSources()` |

Adoption chooses flat vs graph from the schema (and intent).

---

## Query compile and assemble

### Compile (`SelectQuery::projection()` / fetch schema)

- FieldRefs → Public field schemas (aliases as paths; flats get `sourcePath`)  
- Aliased non-field selections → expression schemas  
- Relation selections → relation schemas (recursive)  
- Missing PKs for each source → Implicit field schemas  
- Star/default → expand to Public fields  

### Assemble (place-first)

**Schema is the place base.** When `SelectQuery::needsRowAssemble()` is true
(aliases, flats, or relation loads), `beginFetch()` compiles a
`RepresentationSchema` and `RelationOutputProcessor` places scalars only via
`getPublicScalarPaths()`. Query `EXPLICIT` tags are fetch/authoring only — not a
place fallback.

Plain own-field reads skip assemble and skip schema compile (executor column
names already match place).

Fetch tags (`COLUMN`, `SQL_ONLY`, `INTERNAL`, `DEFAULT`, …) remain Query/runtime only.

### `forPrimaryKey()`

Builds a schema whose Public paths are the primary-key fields. That schema **is** the representation (key-only object). Those paths are Public place, not Implicit — Implicit is for backfill onto a larger shape.

---

## Model boundaries (unchanged intent)

### Definition tree

What can exist: collections, fields, relations, keys, storage names.

### Query graph

What was requested to read/fetch: selections, tags, load options, strategies. Compiles **into** representation schemas; is not itself the durable place model.

### Mapper

Shape conversion only; not persistence provenance.

---

## Runtime: state, sync, flat adoption

### RepresentationState

Attaches a schema to one object instance: field/relation state items + baselines. Multiple instances may share one schema.

### Sync

- Scalar sync: writable **field** schemas (via state items)  
- Relation sync: relation schemas only  
- Expression paths: ignored  

`Session::sync($object, $schema)` walks explicit relation schemas; untracked roots need a homogeneous single-collection schema.

### Flat projection adoption

`RepresentationAdoptionEngine::attach()`:

- Flat: `getSources()` (fields grouped by `sourcePath`)  
- Graph: relation branches + `getRelatedSchema()`  

Writable prepare plans Query `INTERNAL` identity selections via `QueryRepresentationIdentityPlanner` (does not put SQL_ONLY aliases on the schema). Schema Implicit PK fields remain the durable identity provenance for adoption.

See [`session-save-api.md`](./session-save-api.md) and [`writable-select-query-projections.md`](./writable-select-query-projections.md).

---

## Open / next (not blocking this spec)

- `query($schema)` reopen (0001 remainder); nested expression load limits  

---

## Non-goals

- Automatic relation inference from objects/mapper  
- Array root sync  
- Cascade / orphan policies in the schema type  
- SQL generation or transactions on the schema  
- A parallel `BindingTree` / `QueryPlan` place type beside `RepresentationSchema`  
- Storing `"*"` or DB-default expression labels as paths  
- Sharing `SelectionList` / fetch tags as the schema store  

Keep `RepresentationSchema` as the graph/branch class; path is the index.
