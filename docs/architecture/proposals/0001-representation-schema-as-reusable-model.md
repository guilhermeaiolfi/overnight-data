# Proposal 0001: RepresentationSchema as reusable query model

Status: **Proposed** — schema model landed; remaining work is reopen + compile memoization.

**Canonical living model:** [`docs/orm/representation-schema.md`](../../orm/representation-schema.md) (path-as-index, Public/Implicit, expressions, path-first assemble).

**Depends on** [`archive/0002-recursive-projection-levels.md`](./archive/0002-recursive-projection-levels.md) (accepted). **Fetch/path:** [`../decisions/0002-fetch-loadbranch-vs-place-schema.md`](../decisions/0002-fetch-loadbranch-vs-place-schema.md) (accepted; archived proposal [`archive/0003-load-graph-and-schema-as-place.md`](./archive/0003-load-graph-and-schema-as-place.md)).

This document freezes the remaining design: define a result shape once, vary only conditions.

## Landed (do not re-litigate)

These were Phase 1 of the original write-up and now live in the schema spec and compiler:

- `RepresentationExpressionSchema` plus path maps (`fields` / `relations` / `expressions` / `paths[]`)
- Path collision across all three node kinds
- Compiler keeps aliased non-`FieldRef` selections at every level
- Session sync walks fields + relations only; expression paths are ignored
- Recursive nested schemas with the same node kinds as root (proposal 0002)

`SelectQuery::projection()` compiles that graph today. It is **not** memoized, and `query()` does not accept a schema.

## Problem

Developers need one durable **result shape** reused across the application with different filters — the role ATK4 `Model` plays for them:

```php
// Define once
$model = $runtime->query($orderItems)
    ->select(
        $q->id,
        $q->qty,
        $q->price,
        x()->mul($q->qty, $q->price)->as('lineTotal'),
        $q->product->name->as('productName'),
        $q->posts->select('id', 'title'),
    )
    ->projection();

// Use everywhere — same columns/aliases/expressions, different conditions
query($model)->where(...)->fetchAll();
query($model)->where(...)->orderBy(...)->limit(20)->fetchAll();

// Same object for writes (writable subset only)
$session->update($dto, $model);
```

Today:

- `Collection` = table definition (not a select shape).
- `SelectQuery` = full read intent including `where` / `order` / `limit` (ephemeral, not a shared model).
- `RepresentationSchema` via `projection()` = path + write provenance, including expressions.
- There is no schema → query reopen; compilation is uncached across calls.

There is no first-class “define this result structure once, vary only conditions” artifact.

## Goals (remaining)

1. **One public durable artifact** for result shape — keep `RepresentationSchema`, do not invent a parallel `QueryPlan` / selection-graph type.
2. Shape already includes fields, relation loads, and expressions; reopen must restore **all three**.
3. Root **`where` / `having` / `orderBy` / `limit` / `offset` are not part of the shape** — they stay on ephemeral `SelectQuery` instances.
4. Session sync / `update` / `create` keep using the **writable field + relation** subset; computed selections stay read-only.
5. `query($schema)` (and/or `$schema->toQuery()`) materializes a fresh `SelectQuery` with the shape’s selections applied.
6. Compiling `SelectQuery` → schema should be **memoizable / cacheable** (in-process first).

## Non-goals (this proposal)

- ATK4-style ActiveRecord / Model class owning lifecycle + persist API.
- Putting root filters or pagination on the schema.
- Full durable serialization of arbitrary expression ASTs in v1 (may follow later).
- Making every selected expression writable.
- Replacing `Collection` or changing definition storage.
- Round-tripping a schema into the *original* query including where/order/limit (intentionally lossy on those).

## Decision summary

| Concern | Owner |
|---|---|
| Table / columns / relations metadata | `Collection` (unchanged) |
| Durable result shape + write provenance | `RepresentationSchema` (already the path graph) |
| One-shot filters, sort, page | `SelectQuery` (unchanged role) |
| Persistence | `Session` + field/relation schema nodes (unchanged contract) |

```text
SelectQuery  ──projection()──►  RepresentationSchema  (durable, cacheable)
                                      │
                               query($schema) / toQuery()
                                      ▼
                               SelectQuery + where/order/limit  (ephemeral)
```

## Reopen (new)

Either or both (prefer helper + optional method):

```php
query($schema);           // SelectQuery rooted at schema collection, selections applied
$schema->toQuery();       // same; optional sugar
```

Semantics of reopen:

1. `new SelectQuery($schema->getCollection())` (bound executor policy TBD — see Open questions).
2. Apply field schemas as field/alias selections (including flat `sourcePath` fields via relation field refs).
3. Apply expression schemas as `select($expression->as($path))` after **rebind** onto the new query’s sources/refs.
4. Apply relation schemas as relation load / `select(...)` configuration.
5. Leave root conditions, sorts, limit, offset empty.
6. Do not attach writable handler or result class unless explicitly decided later.
7. Re-emit Implicit source PKs as fetch SQL from schema paths (`company.id`); do not run a second identity planner.

`query(SelectQuery)` rules today (must `as()` for subquery source) stay unchanged; `RepresentationSchema` is a new accepted source kind.

Star/`all()` is **already expanded** at compile (living spec: never store `"*"` as a path). Reopen restores the resolved Public field list, not a star token.

### Writes (unchanged call shape)

```php
$session->update($dto, $schema);
$session->sync($dto);
```

Sync walks **fields + relations only**. Expression paths on the DTO are ignored for persistence (same as selecting a non-writable computed column).

### Caching (v1)

- Memoize `projection()` per `SelectQuery` instance (same object → same schema instance).
- Optional: structural memoization later.
- Cross-request serialization of expressions is **deferred** (see Phasing).

## Conversion cost

`query($schema)` **does** rebuild a `SelectQuery` each call. That is intentional and should be cheap relative to SQL:

- Not a re-run of write-provenance inference from scratch in the hot sense of “recompile unknown query.”
- Walk resolved schema nodes + `rebind()` expressions onto new query-scoped `FieldRef`s + `select()`.

Optional later optimization: keep a template query on the schema and `copy()` it. Not required for correctness.

## Explicitly rejected alternatives

| Alternative | Why rejected |
|---|---|
| Parallel `QueryPlan` / selection-graph type beside `RepresentationSchema` | Near-duplicate tree; two public concepts for ~same shape |
| `RepresentationSchema` → full original `SelectQuery` including where/limit | Lossy and wrong for a “model”; filters are per use |
| Schema holds only FieldRefs (no expressions) | Breaks “same result structure everywhere” for real apps |
| Fat ATK4 Model class (query + persist + AR) | Undoes ON Data’s Collection / Query / Schema / Session split |
| Name “Value” for expression nodes | Ambiguous (ids / literals / payloads) |

## Phasing

### Phase 1 — in-process reusable model (remaining MVP)

Landed: expression nodes, path maps, collision rules, compiler fidelity, recursive levels.

Still to build:

1. Implement schema → `SelectQuery` apply (`query($schema)` and/or `toQuery()`).
2. Memoize `SelectQuery::projection()`.
3. Tests: compile round-trip selection fidelity; reopen + extra where; sync still ignores expressions; Implicit PK re-emit.

Approx. remaining effort (rough): smaller than the original 300–450 production LOC estimate; reopen + memoize + tests is the bulk.

### Phase 2 — durable freeze (optional follow-up)

- Serialize schema including expression AST freeze/thaw.
- Needed for cache-across-request / config-stored models.
- Larger cost (expression serialization across the expression taxonomy).

### Phase 3 — polish (optional)

- Template-query memo + `copy()` on reopen.
- Relation default scopes on schema.

## Compatibility / migration

- Existing field/relation/expression schemas and Session behavior remain valid.
- `projection()` return type stays `RepresentationSchema`.
- Callers that inspect schemas already see expression nodes.
- No change required to definition registry format.

## Open questions (may be influenced by other work)

1. **Bound executor on reopen** — `query($schema)` unbound vs requiring `DataRuntime` / carrying executor on schema?
2. **`toQuery()` vs only `query($schema)`** — one or both in public API?
3. **Expression storage form** — live `ValueExpressionInterface` only in v1 (`object` on `RepresentationExpressionSchema` today), or an intermediate serializable IR early?
4. **Flat join-sourced fields** — already modeled via `sourcePath`; any reopen gaps vs compiler?
5. **Naming** — keep `RepresentationSchema` vs later rename to `Projection` (type rename is not required for MVP)?
6. **Interaction with upcoming ideas** — leave room for alternate authoring or storage of shapes without forking a second public tree.

Resolved from the living spec (not open):

- **Star / DEFAULT** — store the expanded Public field list; do not preserve `"*"` on the schema.
- **Identity / INTERNAL** — Implicit source PKs live on the durable schema (`company.id`); fetch plumbing stays off-schema.

## Acceptance criteria (Phase 1 remainder)

- [x] A query with field + expression + relation selections compiles to one `RepresentationSchema` that retains all three kinds.
- [x] Path collision across field / relation / expression is rejected.
- [x] `$session->update($dto, $schema)` / sync does not write expression paths.
- [ ] `query($schema)->where(...)->fetchAll()` returns the same columnar/alias structure as the original select (modulo where).
- [ ] `projection()` on the same `SelectQuery` instance is memoized.
- [x] Docs: this proposal linked from the docs index.
- [ ] Living docs updated when reopen is built.

## References

- [`docs/orm/representation-schema.md`](../../orm/representation-schema.md) — current schema model
- [`docs/orm/session-save-api.md`](../../orm/session-save-api.md) — `projection()` for writes
- [`docs/orm/writable-select-query-projections.md`](../../orm/writable-select-query-projections.md) — writable query provenance
- [`docs/query/query-model.md`](../../query/query-model.md) — `SelectQuery` / `query()` helper
- [`docs/query/expressions-and-conditions.md`](../../query/expressions-and-conditions.md) — expression taxonomy
