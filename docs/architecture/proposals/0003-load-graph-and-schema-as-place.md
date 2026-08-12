# Proposal 0003: LoadBranch destinations + RepresentationSchema as place

Status: **Accepted / closed (mostly done)** on `feat/recursive-projection-levels`

Relates to: [`0002-recursive-projection-levels.md`](./0002-recursive-projection-levels.md), [`0001-representation-schema-as-reusable-model.md`](./0001-representation-schema-as-reusable-model.md).

## Closing note (current)

The fetch/place split is the lasting design. Remaining “purity” work is **intentionally deferred**, not unfinished MVP:

| Done | Intentionally not doing now |
|---|---|
| `LoadBranch` = fetch destinations | Drive **all** own-level place keys from schema alone |
| Schema = place provenance (Query may use it directly) | Full root↔nested `requireFields` unify |
| Load-local parser keys + place→load binds | Reintroduce LoadGraph / FetchPlan |
| Lazy schema compile (only when writable or relations need place) | Treat hybrid assemble as a defect |
| Flat reuse of loaded to-one child destinations | |

**Place-first assemble (when schema is present):** `RelationOutputProcessor::placeKeysFor()` uses `RepresentationSchema::getPublicScalarPaths()` (Public fields + expressions). Implicit paths (e.g. PK backfill) stay on the schema for adoption but are not public place. Without a compiled schema, assemble still falls back to explicit selections.

Field place roles (`RepresentationFieldRole::Public` | `Implicit`) are the durable half of selection meaning; fetch tags stay on Query.

## Problem

Nested `select()` made authoring one API, but three jobs still share one tangled tree (`RelationRef` + `SelectionList` + parser `valueAliases` + `RelationOutputProcessor`):

1. **Fetch** — which columns from which collection, JOIN vs SEPARATE, where/order  
2. **Place** — where those values land on the result/object (`authorName` vs `author.name`)  
3. **Fold rows** — parser nodes (Cycle-style join/link hydration)

That tangle makes JOIN “parity” hard and spreads placement logic across aliases, parser aliases, output processor, and schema `sourcePath`.

## Decision

Two first-class concerns; **do not** invent a third `SpreadPlan` / `LoadGraph` type.

| Concern | Artifact | Role |
|---|---|---|
| **Place** | `RepresentationSchema` (`path` + `sourcePath` + relations) | Where values sit on the representation; ORM provenance; 0001 durable shape |
| **Fetch** | `LoadBranch` tree (root + one branch per `RelationSelection` attach) | What to load per destination; attach mode; load options; place→load binds |

```text
select()
   ├─► RepresentationSchema   // place (path ← sourcePath + field)
   └─► RelationSelectionTree  // which attaches exist
           │
           ▼
     LoadBranch tree          // fetch destinations (JOIN/SEPARATE + columns + binds)
           │
           ▼
     Parser tree              // load-local keys only
           │
           ▼
     assemble(schema, binds)  // walk place graph; read load bags
           │
           ▼
     map / track(schema)
```

### Destination pipeline

```text
Authoring (RelationRef select/load)
        │
        ▼
RelationSelectionTree     ← which attaches exist
        │
        ▼
LoadBranch tree           ← runtime destination per attach (+ root)
        │
        ▼
LoadFieldPlanner          ← bind place-level COLUMNs (per-selection bind)
  assign: local | child destination | skip
  emit:   SQL on that destination + place binds
        │
        ▼
RelationOutputProcessor   ← place keys: EXPLICIT ∪ layout flats; read via placeToLoadKeys / child paths
```

**Invariant:** destination = RelationSelection attaches (+ root), **not** every schema `sourcePath`. Flat `$posts->author->name` does not create an author attach.

### Flats (fetch home vs place)

**Place** is always on the parent (`authorName` on the post; `sourcePath=['author']`).

| Circumstance | Fetch home | Gather into place |
|---|---|---|
| No `author` destination (flat only) | **Parent** (posts) — JOIN/select onto posts query | from parent row |
| `author` already a loaded destination | **Author** destination — select `name` there; skip redundant parent JOIN | from author bag → `authorName` on post |

Never create an author destination **only** because of a flat. Prefer an existing author destination when present. Schema `sourcePath` remains provenance.

Today: flat-only registers as `COLUMN` on the parent `LoadBranch`; assemble places via schema path (`RepresentationSchema::flatPlaceKeysAt`). When a loaded to-one child destination already covers `sourcePath`, fetch requires the field on that child and assemble reads the child bag (no redundant parent JOIN).

### Invariants

1. **One RelationSelection path ⇒ one LoadBranch** ⇒ one where/order/limit/strategy bag via existing selection/ref APIs. Do not duplicate inventories on the branch.  
2. **Many place edges may read one load field** (flat + nested from the same author row — when author is a destination).  
3. **Parser keys are load-local** (stable field / INTERNAL names). Public aliases live on the schema `path` / place keys.  
4. **JOIN vs SEPARATE is a LoadBranch attach mode** — place graph unchanged.  
5. **`RepresentationSource`** remains the place-fields-grouped-by-source view (derived from schema) for writable.

## Non-goals (initial slices)

- Rewriting all loaders’ SQL in one step  
- Full 0001 `query($schema)` reopen  
- Expression AST on schema (still 0001)  
- A parallel SpreadPlan / LoadGraph type next to `RepresentationSchema`  

## Phasing

### Phase 0 — document + extract (historical)

Originally added a read-only `LoadGraph` keyed by source path, including unloaded nodes for flats (`posts.author`). **Superseded:** that inventory duplicated destinations incorrectly for flats and was never used to drive fetch SQL. Removed in the cleanup pass below.

### Phase 1 — schema available before assemble ✅

1. Ensure schema compile runs before fetch on paths that will assemble from schema (writable already `prepare()`s first; extend as needed).  
2. Keep current output processor behavior.

`SelectQuery::beginFetch()` compiles place schema **lazily**: writable prepare first; relation loads that need place compile once; plain reads with no relations skip schema (no PK requirement on ordinary reads). Writable `prepare()` exposes the same schema via `WritablePreparation::getFetchSchema()` (no second compile). Query assemble reads flats from that `RepresentationSchema`.

### Phase 2 — assemble flats from schema ✅

1. Drive flat placement from schema `path`/`sourcePath` while parser remains mostly as today.  
2. Shrink ad-hoc flat handling in output registration.

`RelationOutputProcessor` places scalar keys from explicit own-level selections plus layout flat keys (`sourcePath !== []`). Nested flats register as fetch `COLUMN` only on the parent branch (not as own-level place from tags).

### Phase 3 — parser ← load-local keys only ✅

1. Parser `valueAliases` use load-local keys.  
2. Assemble owns public naming / visibility (hybrid EXPLICIT ∪ flats).  
3. JOIN attach is “same LoadBranch tree, different edge mode.”

Relation branch columns bind `placeKey → loadKey` on the load branch. Own fields load as the field name; flats as a stable relative key (`author__name`); INTERNAL keys stay as planned. `RelationOutputProcessor` reads load keys and writes place paths. JOIN still allocates `__on_data_*` / `l_*` load keys and maps them the same way.

### Phase 4 — collapse dual compile/identity paths ✅

1. Identity planning and schema compile share source-path helpers with relation path APIs.  
2. Remove `plan` / `planLevel` fork once levels are “nodes in one graph.”

`RelationRef::isUnder()` / `relativeTo()` answer under-level / relative-path questions for schema compile, load-branch flat detection, and load-local key naming (ancestor may be a nested `RelationRef` or the root `SelectQuery`). `QueryRepresentationIdentityPlanner::planIdentities(SelectQuery|RelationRef, …)` is the single ensure/resolve path.

### Cleanup pass (after Phase 4) ✅

- [x] Collapse duplicate `remapLoadLocalColumnReferences()` overrides via `RemapsLoadLocalChildFields`.  
- [x] Delete `plan()` / `planLevel()` wrappers; callers use `planIdentities()`.  
- [x] Root load-local parity: one `selectField` path for every `LoadBranch` (root is just the empty-path level). When `RelationSelectionTree` is empty, `LoadRuntime` returns executor rows directly (avoids RootNode collapsing flat has-many joins by identity).

### Load/place simplification (post Phase 4) ✅

Same smell as the old root special-case: unfinished dual paths, not domain rules.

- [x] One assemble path: `RelationOutputProcessor` shares `projectScalars` / `placeKeysFor` for root and nested.  
- [x] Parser nodes are born on **load** keys (no place→load remap trait); `setValueAliases` only binds SQL column order. Keep `LoadBranch::placeToLoadKeys` as the intentional assemble bridge.  
- [x] One schema field pipeline: `fieldsFromSelections` / `fieldFromSelection` / `resolveFieldSource` take `SelectQuery|RelationRef` (root = empty path).  
- [x] One place-schema construction path: writable `prepare()` exposes schema on `WritablePreparation`; `beginFetch` reuses `getFetchSchema()` or compiles once locally — no `instanceof` dual.  
- [x] Dead helpers removed (`publicFieldsForSelection`, `columnFieldName`, `explicitFields`, thin wrappers).

### Cleanup — delete LoadGraph / FetchPlan ✅

- [x] Remove `LoadGraph` / `LoadGraphNode` / `LoadGraphBuilder` (unused for fetch; invented unloaded flat nodes).  
- [x] Remove `FetchPlan` wrapper; thread place via `SelectQuery::getFetchSchema()` / `WritablePreparation` / `LoadRuntime`.  
- [x] Remove `ProjectionLayout`; Query assemble uses `RepresentationSchema` directly as the place boundary.  
- [x] Document destination pipeline + flat fetch-home rules (this proposal).  
- [x] Flat reuse of an existing loaded to-one source destination (fetch home + assemble bag).

### Selection tags (place / fetch / SQL)

| Tag | Role |
|---|---|
| `EXPLICIT` | User-authored selection (replaces the old `SelectionItem::$explicit` bool). Does **not** by itself mean “visible in place.” |
| `COLUMN` | Fetch/hydration scalar on a destination (own field or flat under this level). |
| `INTERNAL` | Opt out of public place / user output; still fetched when required (PK, FKs). Default visibility is public. |
| `SQL_ONLY` | Load-local SQL alias (not a place key); kept in Cycle mapped rows for parsers; stripped by `SelectQuery::publicRow`. |
| `IDENTITY` / `REQUIRED` | Parser / loader bookkeeping. |
| `DEFAULT` | Initial star selection before the first `select()`. |

`PUBLIC` is retired. Assemble (`placeKeysFor`): explicit non-`INTERNAL`/`SQL_ONLY` selections, plus layout flats (`sourcePath !== []`). Schema still backfills PK fields for adoption — those must not alone drive public place.

**Not planned as follow-on for this proposal** (higher risk / separate decision if ever revisited): full root↔nested `requireFields` unify; driving all own-level place keys from schema (excluding identity backfill) instead of `EXPLICIT` tags. The hybrid above is the accepted assemble contract.

## Acceptance (Phase 0) — superseded

- [x] Proposal linked from docs index.  
- ~~`LoadGraphBuilder` builds nodes keyed by source path / flat lands on `posts.author`~~ — **removed**; flats do not invent destinations.  
- [x] No change to fetch results in Phase 0 (historical).

## Acceptance (Phase 1)

- [x] Fetch paths that need place compile schema before LoadRuntime (writable + relation loads); plain no-relation reads skip compile.  
- [x] Writable prepare exposes place schema on `WritablePreparation` (post-identity).  
- [x] `SelectQuery::getFetchSchema()` available after fetch begins when compiled; output shape unchanged.

## Acceptance (Phase 2)

- [x] Nested/root visible scalars are placed from hybrid EXPLICIT ∪ schema flats when layout/schema is present.  
- [x] Nested flat related fields register as load-branch `COLUMN` (not own-level place from tags); output still exposes schema `path`.  
- [x] Existing nested flat + writable flat tests keep passing.

## Acceptance (Phase 3)

- [x] Relation parser value aliases use load-local keys (field name / `author__name` / allocated JOIN alias).  
- [x] Output assemble maps load keys → place paths via branch place→load binding.  
- [x] Nested flat + renamed own-field + writable INTERNAL tests keep passing.

## Acceptance (Phase 4)

- [x] `RelationRef::isUnder()` / `relativeTo()` own under-level / relative path checks.  
- [x] Identity planning uses one `planIdentities(SelectQuery|RelationRef)` ensure/resolve path.  
- [x] Writable prepare + existing identity planner tests keep passing.

## Acceptance (LoadGraph / FetchPlan cleanup)

- [x] No `LoadGraph` / `FetchPlan` types remain.  
- [x] Flat-only fetch still joins under the parent destination (behavior-neutral).  
- [x] Nested flat + schema place tests keep passing.

## Acceptance (flat reuse)

- [x] Flat + loaded to-one child: field required on child destination; parent does not select `author__name`.  
- [x] Assemble places `authorName` from the child bag; nested author container still from the attach.  
- [x] Flat-only (no child destination) unchanged — JOIN under parent.

## Acceptance (close)

- [x] Hybrid assemble documented as intentional.  
- [x] “Schema owns all own-level place keys” marked out of scope for this proposal.  
- [x] Docs index reflects accepted / closed status.

## References

- [`docs/orm/representation-schema.md`](../../orm/representation-schema.md) — place graph today (`path` / `sourcePath`)  
- [`docs/query/relation-loading.md`](../../query/relation-loading.md) — selection / JOIN limits today  
- Parser nodes under `src/Query/Result/Parser/` — current load hydration runtime  
