# Proposal 0003: LoadGraph + RepresentationSchema as place graph

Status: **Accepted direction** — incremental implementation on `feat/recursive-projection-levels`

Relates to: [`0002-recursive-projection-levels.md`](./0002-recursive-projection-levels.md), [`0001-representation-schema-as-reusable-model.md`](./0001-representation-schema-as-reusable-model.md).

## Problem

Nested `select()` made authoring one API, but three jobs still share one tangled tree (`RelationRef` + `SelectionList` + parser `valueAliases` + `RelationOutputProcessor`):

1. **Fetch** — which columns from which collection, JOIN vs SEPARATE, where/order  
2. **Place** — where those values land on the result/object (`authorName` vs `author.name`)  
3. **Fold rows** — parser nodes (Cycle-style join/link hydration)

That tangle makes JOIN “parity” hard and spreads placement logic across aliases, parser aliases, output processor, and schema `sourcePath`.

## Decision

Two first-class graphs; **do not** invent a third `SpreadPlan` type.

| Graph | Artifact | Role |
|---|---|---|
| **Place** | `RepresentationSchema` (`path` + `sourcePath` + relations) | Where values sit on the representation; ORM provenance; 0001 durable shape |
| **Fetch** | `LoadGraph` (nodes keyed by relation **source path**) | What to load from each collection; attach mode; load options |

```text
select()
   ├─► RepresentationSchema   // place (path ← sourcePath + field)
   └─► LoadGraph              // fetch (source path → fields + JOIN/SEPARATE + options)
           │
           ▼
     Parser tree              // runtime of LoadGraph only (load-local keys)
           │
           ▼
     assemble(schema, rows)   // walk place graph; read load bags by sourcePath
           │
           ▼
     map / track(schema)
```

### Invariants

1. **One source path ⇒ one LoadGraph node** ⇒ one where/order/limit/strategy bag. Conflicts throw.  
2. **Many place edges may read one load field** (flat + nested from the same author row).  
3. **Parser keys are load-local** (stable field / INTERNAL names). Public aliases live on the schema `path`.  
4. **JOIN vs SEPARATE is only a LoadGraph attach mode** — place graph unchanged.  
5. **`RepresentationSource`** remains the place-fields-grouped-by-source view (derived from schema); LoadGraph is the fetch plan (may list INTERNAL and default-star expansion).

## Non-goals (initial slices)

- Rewriting all loaders’ SQL in one step  
- Full 0001 `query($schema)` reopen  
- Expression AST on schema (still 0001)  
- A parallel SpreadPlan type next to `RepresentationSchema`

## Phasing

### Phase 0 — document + extract (this proposal)

1. Land this proposal; link from docs index.  
2. Add `LoadGraph` / `LoadGraphNode` / builder that **derives** a read-only fetch view from `SelectQuery` (no runtime behavior change).  
3. Tests: root fields, nested `select`, flat related field appears under the **source** node (`posts.author`), not only under `posts`.

### Phase 1 — schema available before assemble ✅

1. Ensure schema compile runs before fetch on paths that will assemble from schema (writable already `prepare()`s first; extend as needed).  
2. Keep current output processor behavior.

`SelectQuery::fetchAll()` / `fetchOne()` call `beginFetch()` first: compile `RepresentationSchema` + `LoadGraph` into a {@see FetchPlan} before `LoadRuntime`. Writable `prepare()` attaches `LoadGraph` to `QueryRepresentationPlan` after identity planning; the fetch plan reuses that snapshot.

### Phase 2 — assemble flats from schema ✅

1. Drive flat placement from schema `path`/`sourcePath` while parser remains mostly as today.  
2. Shrink ad-hoc flat handling in output registration.

`RelationOutputProcessor` places scalar keys from `RepresentationSchema` field paths (via `FetchPlan`) when present; nested flats no longer need to be `PUBLIC` on the load branch — they register as fetch `COLUMN` only. Parser `valueAliases` still use the place alias as the load key for now (Phase 3 will go load-local).

### Phase 3 — parser ← LoadGraph only ✅

1. Parser `valueAliases` use load-local keys.  
2. `assemble(schema, loadTree)` owns all public naming / visibility.  
3. JOIN attach is “same LoadGraph, different edge mode.”

Relation branch columns bind `placeKey → loadKey` on the load branch. Own fields load as the field name; flats as a stable relative key (`author__name`); INTERNAL keys stay as planned. `RelationOutputProcessor` reads load keys and writes place paths. JOIN still allocates `__on_data_*` load keys and maps them the same way.

### Phase 4 — collapse dual compile/identity paths ✅

1. Identity planning and schema compile share source-path helpers with LoadGraph.  
2. Remove `plan` / `planLevel` fork once levels are “nodes in one graph.”

`RelationRef::isUnder()` / `relativeTo()` answer under-level / relative-path questions for schema compile, load-branch flat detection, and load-local key naming (ancestor may be a nested `RelationRef` or the root `SelectQuery`). `QueryRepresentationIdentityPlanner::planIdentities(SelectQuery|RelationRef, …)` is the single ensure/resolve path.

### Cleanup pass (after Phase 4) ✅

- [x] Collapse duplicate `remapLoadLocalColumnReferences()` overrides via `RemapsLoadLocalChildFields`.  
- [x] Delete `plan()` / `planLevel()` wrappers; callers use `planIdentities()`.  
- [x] Root load-local parity: same place→load bind + assemble as nested levels when place≠load (`selectRootFields`); simple place≡load root fetches may still short-circuit.

## Acceptance (Phase 0)

- [x] Proposal linked from docs index.  
- [x] `LoadGraphBuilder::fromQuery(SelectQuery)` builds nodes keyed by source path.  
- [x] Flat `$posts->author->name->as('authorName')` adds field `name` to node `posts.author` (and posts own fields to `posts`).  
- [x] No change to fetch results in Phase 0.

## Acceptance (Phase 1)

- [x] Every `fetchAll` / `fetchOne` builds a `FetchPlan` (schema + LoadGraph) before LoadRuntime.  
- [x] Writable prepare exposes LoadGraph on `QueryRepresentationPlan` (post-identity).  
- [x] `SelectQuery::getFetchPlan()` available after fetch begins; output shape unchanged.

## Acceptance (Phase 2)

- [x] Nested/root visible scalars are placed from schema field paths when `FetchPlan` schema is present.  
- [x] Nested flat related fields register as load-branch `COLUMN` (not place `PUBLIC`); output still exposes schema `path`.  
- [x] Existing nested flat + writable flat tests keep passing.

## Acceptance (Phase 3)

- [x] Relation parser value aliases use load-local keys (field name / `author__name` / allocated JOIN alias).  
- [x] Output assemble maps load keys → place paths via branch place→load binding.  
- [x] Nested flat + renamed own-field + writable INTERNAL tests keep passing.

## Acceptance (Phase 4)

- [x] `RelationRef::isUnder()` / `relativeTo()` own under-level / relative path checks.  
- [x] Identity planning uses one `planIdentities(SelectQuery|RelationRef)` ensure/resolve path.  
- [x] Writable prepare + existing identity planner tests keep passing.

## References

- [`docs/orm/representation-schema.md`](../../orm/representation-schema.md) — place graph today (`path` / `sourcePath`)  
- [`docs/query/relation-loading.md`](../../query/relation-loading.md) — selection / JOIN limits today  
- Parser nodes under `src/Query/Result/Parser/` — current load hydration runtime  
