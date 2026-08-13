# ADR 0002: Fetch (`LoadBranch`) vs place (`RepresentationSchema`)

Status: Accepted

Supersedes proposal [`../proposals/archive/0003-load-graph-and-schema-as-place.md`](../proposals/archive/0003-load-graph-and-schema-as-place.md) (archived).

Living contract: [`../../orm/representation-schema.md`](../../orm/representation-schema.md) and [`../../query/relation-loading.md`](../../query/relation-loading.md).

## Context

Nested `select()` made one authoring API, but fetch, place, and row-fold still shared one tangled tree (`RelationRef` + `SelectionList` + parser aliases + assemble). That spread placement across aliases, parser keys, output processor, and schema `sourcePath`, and invited parallel “plan” types (`LoadGraph`, `FetchPlan`, `ProjectionLayout`).

## Decision

Keep **two** first-class concerns. Do **not** invent a third place/fetch plan type.

| Concern | Artifact | Role |
|---|---|---|
| **Place** | `RepresentationSchema` (path index, `sourcePath`, relations, Public/Implicit) | Where values sit on the representation; ORM provenance |
| **Fetch** | `LoadBranch` tree (root + one branch per `RelationSelection` attach) | What to load per destination; JOIN/SEPARATE; place→load binds |

```text
select()
   ├─► RepresentationSchema   // place
   └─► RelationSelectionTree  // which attaches exist
           ▼
     LoadBranch tree          // fetch destinations
           ▼
     load() → planScalars → parser
           ▼
     assemble(schema, PlaceBinding)  // when needsRowAssemble()
```

### Invariants

1. **Destination = RelationSelection attaches (+ root)**, not every schema `sourcePath`. Flat `$posts->author->name` does not create an author attach.
2. **Flats:** place stays on the parent (`authorName`, `sourcePath=['author']`). Fetch home is the parent unless a loaded to-one child destination already covers that path — then require the field on the child and assemble from the child bag.
3. **Parser keys are load-local.** Public naming is schema place (`getPublicScalarPaths()` when assemble runs).
4. **`EXPLICIT` is authoring/fetch**, not assemble place. Place roles on schema are `Public` | `Implicit`.
5. **Query may import `RepresentationSchema`** as the intentional place boundary; fetch tags stay on Query.

### Rejected

- `LoadGraph` / `FetchPlan` / `ProjectionLayout` as parallel place or fetch inventories  
- Inventing destinations only because a flat `sourcePath` exists  
- Hybrid assemble that falls back to `EXPLICIT` tags for public place when a schema is compiled  

## Consequences

- When `SelectQuery::needsRowAssemble()` is true, `beginFetch()` compiles (or reuses) a place schema; `RelationOutputProcessor` places only via `getPublicScalarPaths()`.
- Plain own-field reads skip assemble and schema compile (executor keys already match place).
- Fetch prepare is destinations + `load()` (JOIN/SEPARATE and mark required names), then one `planScalars` (emit SQL aliases; JOIN uses dotted `posts.title`), then parser. Nested parser nodes are still created children-first (`AbstractLoader::register`); the root node is created before top-level `initNode` so loaders can see the parent node. Do not add a third place/fetch plan type.
- Deferred separately: `query($schema)` reopen (proposal 0001); richer path filters.

## References

- Archived implementation history: [`../proposals/archive/0003-load-graph-and-schema-as-place.md`](../proposals/archive/0003-load-graph-and-schema-as-place.md)  
- Related open work: [`../proposals/0001-representation-schema-as-reusable-model.md`](../proposals/0001-representation-schema-as-reusable-model.md), [`../proposals/0002-recursive-projection-levels.md`](../proposals/0002-recursive-projection-levels.md)  
