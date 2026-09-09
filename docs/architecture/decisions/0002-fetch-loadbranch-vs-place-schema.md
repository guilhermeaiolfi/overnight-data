# ADR 0002: Fetch (`LoadBranch`) vs path (`RepresentationSchema`)

Status: Accepted

Supersedes proposal [`../proposals/archive/0003-load-graph-and-schema-as-place.md`](../proposals/archive/0003-load-graph-and-schema-as-place.md) (archived).

Living contract: [`../../orm/representation-schema.md`](../../orm/representation-schema.md) and [`../../query/relation-loading.md`](../../query/relation-loading.md). Birds-eye: read step R2 in [`../../read-write.md`](../../read-write.md). Archived proposal 0003 still says “place” for this same concern.

## Context

Nested `select()` made one authoring API, but fetch, path, and row-fold still shared one tangled tree (`RelationRef` + `SelectionList` + parser aliases + assemble). That spread mapping across aliases, parser keys, output processor, and schema `sourcePath`, and invited parallel “plan” types (`LoadGraph`, `FetchPlan`, `ProjectionLayout`).

## Decision

Keep **two** first-class concerns. Do **not** invent a third path/fetch plan type.

| Concern | Artifact | Role |
|---|---|---|
| **Path** | `RepresentationSchema` (path index, `sourcePath`, relations, Public/Implicit) | Where values sit on the representation; ORM provenance |
| **Fetch** | `LoadBranch` tree (root + one branch per `RelationSelection` attach) | What to load per destination; JOIN/SEPARATE; path→load binds |

```text
select()
   ├─► RepresentationSchema   // path
   └─► RelationSelectionTree  // which attaches exist
           ▼
     LoadBranch tree          // fetch destinations
           ▼
     load() → planScalars → parser
           ▼
     assemble(schema, PathBinding)  // when needsRowAssemble()
```

### Invariants

1. **Destination = RelationSelection attaches (+ root)**, not every schema `sourcePath`. Flat `$posts->author->name` does not create an author attach.
2. **Flats:** the path stays on the parent (`authorName`, `sourcePath=['author']`). Fetch home is the parent unless a loaded to-one child destination already covers that path — then require the field on the child and assemble from the child bag.
3. **Parser keys are load-local.** Public naming is the schema path (`getPublicScalarPaths()` when assemble runs).
4. **`EXPLICIT` is authoring/fetch**, not assemble path. Path roles on schema are `Public` | `Implicit`.
5. **Query may import `RepresentationSchema`** as the intentional path boundary; fetch tags stay on Query.

### Rejected

- `LoadGraph` / `FetchPlan` / `ProjectionLayout` as parallel path or fetch inventories  
- Inventing destinations only because a flat `sourcePath` exists  
- Hybrid assemble that falls back to `EXPLICIT` tags for public paths when a schema is compiled  
- `MaterializedRow` (or any per-row public/identity/child object) wrapping the identity overlay. Assemble already writes a same-level overlay beside public rows. `SelectQuery::publicRow()` is only the fast-path / `iterate()` `INTERNAL` / `SQL_ONLY` mask. Do not break `WritableResultHandler::track()` to pass a carrier; adoption already reads nested overlay arrays.

## Consequences

- When `SelectQuery::needsRowAssemble()` is true, `beginFetch()` compiles (or reuses) a path schema; assemble writes public scalars via `getPublicScalarPaths()` and identity overlay keys via Implicit/PK paths. `LoadRuntime` keeps those overlays beside public rows for writable `track()`; the fast path uses public rows as the overlay.
- Plain own-field reads skip assemble and schema compile (executor keys already match paths).
- Do not add a third path/fetch plan type. Fetch stays on `LoadBranch`; path stays on `RepresentationSchema`. Identity stays overlay arrays, not a per-row carrier type.
- Deferred separately: `query($schema)` reopen ([`../proposals/0001-representation-schema-as-reusable-model.md`](../proposals/0001-representation-schema-as-reusable-model.md)).

## References

- Living path model: [`../../orm/representation-schema.md`](../../orm/representation-schema.md)
- Nested `select()` (landed): [`../proposals/archive/0002-recursive-projection-levels.md`](../proposals/archive/0002-recursive-projection-levels.md)
- Archived implementation history: [`../proposals/archive/0003-load-graph-and-schema-as-place.md`](../proposals/archive/0003-load-graph-and-schema-as-place.md)
- Related open work: [`../proposals/0001-representation-schema-as-reusable-model.md`](../proposals/0001-representation-schema-as-reusable-model.md)  
