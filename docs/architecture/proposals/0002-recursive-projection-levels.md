# Proposal 0002: Recursive projection levels

Status: **Proposed** — Phase A + Phase C landed on `feat/recursive-projection-levels`

Relates to: [`0001-representation-schema-as-reusable-model.md`](./0001-representation-schema-as-reusable-model.md) (should inform / precede 0001).

This document captures the design for unifying root and nested relation projection so every level shares one selection model.

**Landed so far:** `RelationRef::select()` (including string short form `select('id','title')`) over a per-level `SelectionList`, relation selection tree/load branch registration of own-level aliases, recursive schema compile for nested aliases + relative flat `sourcePath`, per-level alias/relation name collisions, nested flat field fetch onto the level payload (JOIN and SEPARATE), and nested INTERNAL identity planning for writable flats (Phase C). JOIN allows same-level field selections (including aliases) and nested flat FieldRefs; non-field expressions still require `separate()`. `RelationRef::fields()` was removed. Deferred: nested expressions (0001).

## Problem

Root `SelectQuery` supports a rich projection language. Nested relation levels do not.

```text
Root:
  SelectionList → fields | aliases | expressions | subqueries | flat related fields | relation loads

Nested relation (RelationSelection):
  ?list<string> field names only  (+ where / order / limit / strategy / visibility)
```

Examples of the gap:

```php
// Works at root — flat related field + alias + expression
$u->select(
    $u->id,
    $u->profile->name->as('profileName'),
    x()->mul($u->qty, $u->price)->as('lineTotal'),
    $u->posts->select('id', 'title'), // nested: names only
);

// Not expressible today on the posts level:
// - expression / aggregate on a post row
// - alias different from field name on a nested payload
// - flat grandchild field onto the post object
//   (e.g. posts.author.name as authorName on each post)
```

`RepresentationSchema` is **already recursive** (`RepresentationRelationSchema` → nested schema). The asymmetry is in **query selection storage, load/output, and schema compilation inputs** — not in the schema tree shape.

That dual model adds complexity:

- one authoring API (`select(...)`; former `fields()` sugar removed)
- two stores (`SelectionList` vs `list<string>`)
- three compiler passes at root vs a fields-only nested compile
- docs that must explain two projection rules

## Goals

1. **One projection vocabulary at every level** — fields, aliases, expressions, subqueries, flat related-field projections, and nested relation loads.
2. **One recursive logic path** for compile, load public projection, and (later) schema reopen — root is not a special case except for being the query root (FROM / executor binding).
3. Nested payloads can carry the same structural richness root results already can.
4. Preserve existing relation **load options** (`where`, `orderBy`, `limit`, `offset`, `strategy`, visibility) as level-local query modifiers, not as substitutes for projection.
5. Keep `RepresentationSchema` as the compiled shape target; fill nested schemas with the same node kinds root can produce (including future expression nodes from 0001).
6. Prefer **simplifying** dual paths over adding a third parallel API.

## Non-goals

- Changing definition `Collection` / relation metadata.
- Putting root `where` / `order` / `limit` onto nested schemas as durable model state (see 0001 for shape vs filters).
- Requiring JOIN loaders to support every nested option in v1 if separate-query path can deliver correctness first (document limits explicitly).
- Rewriting Session sync graph walking (already recursive on relation schemas).
- Implementing 0001 reopen/`query($schema)` in this proposal (but design must not block it).

## Decision summary

Treat each load level as a **projection scope**:

```text
ProjectionLevel
├── selections  (same kinds as today's root SelectionList)
├── relations   (child ProjectionLevels / RelationRefs)
└── load options (where, order, limit, offset, strategy, visibility)  // relation levels only
```

Root `SelectQuery` is the top `ProjectionLevel` (plus root-only concerns: FROM source, root where/having/group/order/limit, executor).

A nested relation is the same projection model scoped to the related collection, plus relation load options.

```text
Today                          Target
─────                          ──────
Root: SelectionList            Level: SelectionList (or equivalent)
Nested: list<field names>      Level: SelectionList (or equivalent)
Compiler: 3 root passes        Compiler: compileLevel(schema, level) recursive
         + fields-only nest
```

## Target authoring shape

Illustrative API (exact method names TBD — see Open questions):

```php
$u->select(
    $u->id,
    $u->name,
    $u->posts->select( // or select-equivalent on RelationRef
        $u->posts->id,
        $u->posts->title,
        x()->lower($u->posts->title)->as('titleLower'),
        $u->posts->author->name->as('authorName'), // flat onto post row
        $u->posts->comments->select(
            $u->posts->comments->id,
            $u->posts->comments->body,
        ),
    )->where(x()->eq($u->posts->published, true)),
);
```

Compatibility intent:

- `$rel->select('id', 'title')` is the short form for selecting those direct fields (identity aliases). Former `$rel->fields(...)` was removed.
- Bare `$rel` / `$rel->load()` keeps “all visible fields” defaults.
- Existing root `select($u->profile->name->as('profileName'))` remains valid (flat projection onto the **current** level — root today, any level after this work).

## Internal model changes

### Selection storage

| Component | Today | Target |
|---|---|---|
| `SelectQuery` | `SelectionList` | unchanged role (root level selections) |
| `RelationSelection` | `?list<string> $fields` | selection list (expressions + aliases + stars + relation-sourced field refs), not only names |

Nested relation loads still register child `RelationRef` / selection-tree edges; scalar richness moves into per-level selections.

### Load / output

| Component | Today | Target |
|---|---|---|
| `RootLoadBranch::registerPublicSelections` | full public `SelectionList` | becomes the generic “register level selections” path |
| `RelationLoadBranch::addPublicFields` | `field->as(fieldName)` only | register full public selections for that level (aliases, expressions) |
| `RelationOutputProcessor` | keys = field names | keys = selection public paths / aliases; nested containers unchanged in spirit |
| Separate-query / JOIN loaders | project name allowlists | project level selections (JOIN may lag — see phasing) |

### Schema compilation

Replace root-specialized passes with one recursive compiler:

```text
compileLevel(RepresentationSchema $schema, ProjectionLevel $level):
  - field / star / aliased FieldRef → RepresentationFieldSchema
      (sourcePath relative to this level’s root collection)
  - non-FieldRef expressions → RepresentationExpressionSchema (when 0001 lands;
      until then: either keep in query-only path or park as expression nodes early)
  - relation loads → RepresentationRelationSchema + compileLevel(relatedSchema, childLevel)
  - PK enrichment for this level’s collection / source paths (as today at root)
```

Flat related fields at a nested level get `sourcePath` **relative to that nested schema**, not only on the query root schema.

Identity planning for writable nested flat projections follows the same relative-source rules via `QueryRepresentationIdentityPlanner::planIdentities()` (root or nested level) and per-relation identities on the prepare plan.

### Collision rules

Today: root alias vs top-level relation name (`assertNoRelationSelectionCollisions`).

Target: **per level** — a public selection path must not collide with a child relation container name at that same level.

## What this unlocks

- Nested DTOs with computed columns and stable aliases.
- Flat projections onto nested objects (grandchild fields without forcing another nested container when the app wants a flat property).
- One mental model for “what does this object look like?” at every depth.
- A natural backbone for proposal 0001: durable schema + reopen become recursive for free if levels are uniform.
- Less permanent special-casing in compiler and load public-field registration.

## Complexity removed vs added

**Removed (ongoing):**

- Dual projection APIs as the *real* model (`fields()` removed; `select()` covers both short names and rich projection).
- Dual nested vs root compile field assembly.
- Docs/rules that say “only direct fields under relations.”

**Added (implementation):**

- Per-level selection lists on relation selections.
- Loader/output support for non-identity aliases and expressions in nested payloads.
- Per-level collision checks.
- Nested flat `sourcePath` + writable identity planning.
- Copy/rebind of nested selection ASTs through `SourceMap`.

Net: short-term implementation cost; long-term model cost should drop.

## Relationship to proposal 0001

| 0002 (this) | 0001 |
|---|---|
| Uniform projection **language** at every level | Durable schema + `query($schema)` reopen |
| Feeds recursive `RepresentationSchema` richly | Extends schema with expression nodes + cache |
| Should be designed **first** (or as shared foundation) | Should assume recursive levels, not root-only expressions |

Recommended sequencing:

1. **0002** — recursive projection levels (query + load + compiler → schema).
2. **0001** — expression nodes on schema (all levels), memoize `projection()`, `query($schema)` reopen that recursively applies level selections.

If 0001 is implemented first without 0002, expect rework of reopen and expression attachment for nested schemas.

Until 0001, 0002 may still compile nested field/flat-field schemas fully and either:

- keep nested expressions query/load-only (not in schema), or
- introduce `RepresentationExpressionSchema` early as part of 0002’s compiler output (overlap with 0001 — acceptable if coordinated).

## Phasing

### Phase A — model + field/alias parity

1. Replace nested `list<string>` with a selection list capable of `FieldRef` + aliases (+ star).
2. String-name `select('id', 'title')` → that list (former `fields()` sugar removed).
3. Recursive compiler for field + flat related-field schemas at every level.
4. Load/output identity and aliased field names at nested levels.
5. Per-level collision checks.
6. Tests: nested aliases; nested flat grandchild field; schema `sourcePath` relative to nested schema; string-name `select()` short form.

### Phase B — expressions / subqueries at nested levels

1. Allow expression selections in nested lists.
2. Loader/translator support for nested computed columns.
3. Align with 0001 expression schema nodes (or land them here).

### Phase C — writable nested flat projections ✅

1. Extend identity planning / adoption for nested-level flat `sourcePath`s — `QueryRepresentationIdentityPlanner::planIdentities()`, per-relation identities on `QueryRepresentationPlan`, pre-attach flat hydrate in `WritableQueryResultTracker`, branch INTERNAL registration, recursive strip of `_od_internal_*` from public nested payloads.
2. Document writable boundaries per level (same rules as root, recursively) — see [`writable-select-query-projections.md`](../../orm/writable-select-query-projections.md).

### Phase D — JOIN loader parity (nested flats) ✅

1. Nested flat FieldRefs work under JOIN as well as SEPARATE (same `LoadFieldPlanner` emit path).  
2. Non-field expressions in nested `select()` still require `separate()` (align with 0001 expression schema when landed).

## Compatibility / migration

- Existing `$rel->select('a', 'b')` and `$rel->load()` keep working.
- Root flat projections unchanged.
- Nested result keys that were field names stay field names when using string-name `select('id', 'title')`.
- New aliases at nested levels are opt-in via explicit `.as(...)` / nested select.
- Schema consumers that assumed nested related schemas only contain same-name fields must tolerate aliases and (later) expression paths.

## Resolved decisions

### 1. API surface — `RelationRef::select(...)`

Use `::select(...)` mirroring root `SelectQuery::select`.

**Simple direct fields** look like root field refs (not a separate string-list API as the primary form):

```php
// Preferred — same vocabulary as root
$u->posts->select(
    $u->posts->id,
    $u->posts->title,
);

// With alias
$u->posts->select(
    $u->posts->title->as('headline'),
);

// String names are the short form for the common “just these column names” case
$u->posts->select('id', 'title');
// ≡ select($u->posts->id, $u->posts->title) with identity aliases
```

So everyday nested shapes stay short via `select('id', 'title')`; full projection power (aliases, flats, nested refs) uses the same `select()` API. Former `fields()` was removed.

### 2. Default fields — clear like root

**Yes.** Nested `select()` follows root clearing rules:

- Relation-only / child-relation-only `select(...)` (no scalar/value expressions at that level) keeps that level’s default scalar fields (same spirit as root relation-only `select($u->posts)`).
- Passing any scalar/value expression at that level **clears** defaults and uses the explicit scalar list.

Example:

```php
// Clears posts defaults — only id + nested author (plus whatever PK enrichment loaders/schema add)
$u->posts->select(
    $u->posts->id,
    $u->posts->author->select($u->posts->author->name),
);

// Relation-child-only at posts level — posts keeps default scalars; author is nested
$u->posts->select(
    $u->posts->author->select($u->posts->author->name),
);

// NOT the same as traverse-only (posts stays unloaded intermediate):
// $u->posts->author->select($u->posts->author->name);
```

Calling `select()` on a level puts that level in play. Deep traverse without `select()`/`load()` on the ancestor keeps today’s intermediate container behavior.
Rationale: one rule everywhere. Surprising “silent defaults” after an explicit scalar `select()` is worse than making people write `$level->all()` / star when they want everything plus extras.

### 3. Star / `all()` at nested levels

**Yes** — same semantics as root: all visible fields of **that** level’s collection.

```php
$u->posts->select(
    $u->posts->all(), // or star()
    $u->posts->author->name->as('authorName'),
);
```

### 4. JOIN vs SEPARATE — Phase A/B + D

**Delivered:** nested field + alias + flat FieldRef projection on **JOIN** and **SEPARATE_QUERY**.

Non-field expressions with JOIN still throw `RelationLoaderException::richNestedSelectionRequiresSeparate` — use `separate()` until expression Phase / 0001.

### 5. Expression schema timing

**Wait for 0001.** Phase A/B of 0002 may still *load/query* nested expressions eventually, but `RepresentationExpressionSchema` and schema retention of expressions stay with 0001. Phase A focuses on field + alias + flat related-field parity.

### 6. Hidden INTERNAL selections

**Yes** — mirror root tagging at nested levels for writable flat identity (INTERNAL PK selections stripped from public nested payloads, required for adoption).

### 7. Intermediate visible-only relation segments

This is existing relation-loading behavior, not a new concept. Clarified below; **keep current ancestor defaults**.

When you configure a deep path without loading the middle collection’s own fields:

```php
$u->posts->author->select('name');
// or later: $u->posts->author->select($u->posts->author->name);
```

Today each path segment has:

- `load` — expose that relation’s own scalar fields
- `visible` — keep that relation as a public nested container

Defaults for **traversed intermediate** segments:

- `load = false`
- `visible = true`

So `posts` appears as a structural container (no post columns), and `author` is nested inside. Hidden intermediates can promote descendants (see `relation-loading.md`).

**Decision for nested `select()`:**

- Configuring / selecting on a **deeper** relation does **not** by itself clear or load ancestors’ scalars.
- Ancestors keep today’s traverse defaults (`load=false`, `visible=true`) unless that ancestor level itself gets `select()` / `load()`.
- Calling `select(...)` on a level marks **that** level loaded and applies that level’s default-clearing rules only.

```php
// posts = visible container, not loaded (no post scalars)
// author = loaded with name only (defaults cleared by scalar select)
$u->posts->author->select($u->posts->author->name);

// posts = loaded; defaults cleared; only id + nested author
$u->posts->select(
    $u->posts->id,
    $u->posts->author->select($u->posts->author->name),
);
```

## Acceptance criteria (Phase A)

- [x] Nested level can select direct fields with aliases different from field names; results and schema paths use the alias.
- [x] Nested level can flat-project a related field onto that level’s payload with correct relative `sourcePath` in `RepresentationSchema`.
- [x] `$rel->select('id', 'title')` short form; former `fields()` removed.
- [x] Schema compilation for nested branches shares the same field/flat-field logic path as root (no fields-name-only special case for the happy path).
- [x] Per-level path collision with child relation names is rejected.
- [x] Docs: `relation-loading.md` updated for recursive projection; this proposal linked from the docs index.

## Acceptance criteria (Phase C)

- [x] Nested-level flat related fields get INTERNAL identity selections planned onto that relation’s selection list.
- [x] Writable export of nested items with flat related fields hydrates related records and flushes updates to the correct source collection.
- [x] Public nested payloads strip `_od_internal_*` keys recursively.

## References

- [`docs/query/relation-loading.md`](../../query/relation-loading.md) — current nested `select()` model (string short form; `fields()` removed)
- [`docs/query/query-model.md`](../../query/query-model.md) — root `select()` vocabulary
- [`docs/orm/representation-schema.md`](../../orm/representation-schema.md) — already-recursive schema
- [`docs/orm/writable-select-query-projections.md`](../../orm/writable-select-query-projections.md) — root flat writable projections
- [`0001-representation-schema-as-reusable-model.md`](./0001-representation-schema-as-reusable-model.md) — durable schema / reopen (depends on this direction)
