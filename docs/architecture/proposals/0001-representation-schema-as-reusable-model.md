# Proposal 0001: RepresentationSchema as reusable query model

Status: **Proposed** — expression map + compile retention partially landed on `feat/recursive-projection-levels`; `query($schema)` reopen and memoized `projection()` still open.

Supersedes / relates to: current `SelectQuery::projection()` → `RepresentationSchema` (place + write provenance; expressions retained when aliased).

**Depends on direction in** [`0002-recursive-projection-levels.md`](./0002-recursive-projection-levels.md): nested levels should gain the same projection vocabulary as root before (or while) durable schema reopen is built — otherwise 0001 risks root-only expression/reopen work that must be redone. Field/alias/flat parity largely landed; nested expression *load* still limited (JOIN requires `separate()`).

**Also relates to** [`0003-load-graph-and-schema-as-place.md`](./0003-load-graph-and-schema-as-place.md) (accepted/closed): schema is place; assemble still hybrid (EXPLICIT tags ∪ schema flats). Schema-owned full public place is a follow-on after expression/API enrich.

This document freezes the design discussion so other work can land first and still influence the final shape.

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
- `RepresentationSchema` via `projection()` = place + write provenance; **aliased** non-`FieldRef` expressions compile to `RepresentationExpressionSchema`; there is no schema → query reopen; compilation is uncached across calls.

There is no first-class “define this result structure once, vary only conditions” artifact.

## Goals

1. **One public durable artifact** for result shape — extend `RepresentationSchema`, do not invent a parallel `QueryPlan` / selection-graph type.
2. Shape includes **all selections** needed for a stable application result: fields, relation loads, **and** expressions / aggregates / subqueries.
3. Root **`where` / `having` / `orderBy` / `limit` / `offset` are not part of the shape** — they stay on ephemeral `SelectQuery` instances.
4. Session sync / `update` / `create` keep using the **writable field + relation** subset; computed selections are read-only and ignored by sync.
5. `query($schema)` (and/or `$schema->toQuery()`) materializes a fresh `SelectQuery` with the shape’s selections applied.
6. Compiling `SelectQuery` → schema remains the authoring path; schema compile should be **memoizable / cacheable** (in-process first).

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
| Durable result shape + write provenance | `RepresentationSchema` (extended) |
| One-shot filters, sort, page | `SelectQuery` (unchanged role) |
| Persistence | `Session` + field/relation schema nodes (unchanged contract) |

```text
SelectQuery  ──projection()──►  RepresentationSchema  (durable, cacheable)
                                      │
                               query($schema) / toQuery()
                                      ▼
                               SelectQuery + where/order/limit  (ephemeral)
```

## Schema model extension

### Existing nodes (keep)

1. **`RepresentationFieldSchema`** — scalar path → collection field (+ `sourcePath`, writability, `skipWhenMissing`).
2. **`RepresentationRelationSchema`** — relation path → nested `RepresentationSchema` (+ load knowledge).

### New node

3. **`RepresentationExpressionSchema`** — computed / non-column selection:
   - public path / alias (same path-collision rules as fields/relations)
   - expression AST (`ValueExpressionInterface` or equivalent stored form)
   - always **non-writable** for Session sync
   - does not participate in record-field adoption

Suggested storage on `RepresentationSchema`:

```text
fields[]       path → RepresentationFieldSchema
relations[]    path → RepresentationRelationSchema
expressions[]  path → RepresentationExpressionSchema
paths[]        ordered union of all paths (collision across all three maps)
```

Exact property names are implementation detail; path uniqueness across all node kinds is required.

### What belongs on the schema

| Included | Excluded |
|---|---|
| Root collection | Root `where` / `having` |
| Field selections (+ aliases as paths) | Root `orderBy` |
| Flat related field selections (`sourcePath`) | Root `limit` / `offset` |
| Relation selection tree (fields / nested) | Executor / Session / result class |
| Expression / aggregate / subquery selections | Ad-hoc joins used only for filtering |
| Writability / PK enrichment on field nodes | |

Relation-branch default `where` / `order` / `limit` (ATK-like scoped relations) is **out of v1** unless a follow-up explicitly adds it; instance queries may still configure relations after reopen if the query API allows.

## API

### Authoring (exists, behavior expands)

```php
$schema = $query->projection(); // RepresentationSchema
```

Compiler changes:

- Continue compiling `FieldRef` / aliased `FieldRef` → field schemas (including relation-sourced flat fields).
- Continue compiling relation selections → relation schemas.
- **Stop dropping** non-`FieldRef` scalar selections; compile them → `RepresentationExpressionSchema`.
- Session-facing enrichment (read-only PK injection, writability, `skipWhenMissing`) stays on field nodes only.

### Reopen (new)

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

`query(SelectQuery)` rules today (must `as()` for subquery source) stay unchanged; `RepresentationSchema` is a new accepted source kind.

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

### Phase 1 — in-process reusable model (this proposal’s MVP)

1. Add `RepresentationExpressionSchema`.
2. Extend `RepresentationSchema` path maps + collision rules.
3. Compiler keeps expression selections.
4. Implement schema → `SelectQuery` apply (`query($schema)` and/or `toQuery()`).
5. Memoize `SelectQuery::projection()`.
6. Tests: compile round-trip selection fidelity; reopen + extra where; sync still ignores expressions; path collisions.

Approx. effort (rough): **~300–450 production LOC**, **~550–850 with tests**.

### Phase 2 — durable freeze (optional follow-up)

- Serialize schema including expression AST freeze/thaw.
- Needed for cache-across-request / config-stored models.
- Larger cost (expression serialization across the expression taxonomy).

### Phase 3 — polish (optional)

- Template-query memo + `copy()` on reopen.
- Relation default scopes on schema.
- Docs rename of “persistence provenance” → “result shape + provenance” in `representation-schema.md`.

## Compatibility / migration

- Existing field/relation schemas and Session behavior remain valid.
- `projection()` return type stays `RepresentationSchema`.
- Callers that assumed non-field selections were absent from schemas must tolerate expression nodes (inspect APIs should expose them explicitly).
- No change required to definition registry format.

## Open questions (may be influenced by other work)

1. **Bound executor on reopen** — `query($schema)` unbound vs requiring `DataRuntime` / carrying executor on schema?
2. **Star / DEFAULT selections** — store resolved field list vs preserve star and resolve at reopen?
3. **Expression storage form** — live `ValueExpressionInterface` only in v1, or an intermediate serializable IR early?
4. **`toQuery()` vs only `query($schema)`** — one or both in public API?
5. **Flat join-sourced fields** — already partially modeled via `sourcePath`; any reopen gaps vs compiler?
6. **Identity / INTERNAL selections** — remain prepare-time only (`QueryRepresentationIdentityPlanner`), not stored on durable schema?
7. **Naming** — keep `RepresentationSchema` vs later rename to `Projection` (type rename is not required for MVP)?
8. **Interaction with upcoming ideas** — leave room for alternate authoring or storage of shapes without forking a second public tree.

## Acceptance criteria (Phase 1)

- [ ] A query with field + expression + relation selections compiles to one `RepresentationSchema` that retains all three kinds.
- [ ] `query($schema)->where(...)->fetchAll()` returns the same columnar/alias structure as the original select (modulo where).
- [ ] `$session->update($dto, $schema)` / sync does not write expression paths.
- [ ] Path collision across field / relation / expression is rejected.
- [ ] `projection()` on the same `SelectQuery` instance is memoized.
- [ ] Docs: this proposal linked from the docs index; implementation docs updated when built.

## References

- [`docs/orm/representation-schema.md`](../../orm/representation-schema.md) — current schema model
- [`docs/orm/session-save-api.md`](../../orm/session-save-api.md) — `projection()` for writes
- [`docs/orm/writable-select-query-projections.md`](../../orm/writable-select-query-projections.md) — writable query provenance
- [`docs/query/query-model.md`](../../query/query-model.md) — `SelectQuery` / `query()` helper
- [`docs/query/expressions-and-conditions.md`](../../query/expressions-and-conditions.md) — expression taxonomy
