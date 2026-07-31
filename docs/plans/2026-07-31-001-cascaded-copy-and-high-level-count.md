# Cascaded `copy()` and high-level `SelectQuery::count()`

Status: Implemented  
Date: 2026-07-31

## Goal

Make `SelectQuery::copy()` the durable, first-class deep-clone of an owned query graph, keep `rebind(SourceMap)` as the same cascade with outer anchors, and rebuild `SelectQuery::count()` as a high-level reshape of a copy that executes through a **scalar count execution** path built on ordinary select translation (`fetchOne` / executor `fetchOne`) — so count semantics live in the query layer, not in each adapter.

Executors are not required to expose `count()`. They must support the ordinary `SelectQuery` shapes count emits (aggregates and, for some cases, derived `FROM`). Unsupported shapes fail at translation/execution like any other select.

## Background

Source rebinding (`SourceMap`, identity anchors, structural relation resolve) already fixed the onlegis failure mode: transplanting filters onto a second root left `FieldRef`s pointing at foreign sources.

`SelectQuery::count()` was then added as a low-level executor API (`QueryExecutorInterface::count()` + Cycle `translateCount()`). That works, but it pushes DISTINCT-pk / composite dedupe / grouped `SELECT 1` wrap rules into every adapter.

High-level count is preferable: reshape a query, then run a normal select. That reshape must not reuse the caller's object (mutation) and must not invent a shallow second root with unrebound expressions. A full cascading `copy()` is the correct base.

Today `copy()` is already implemented as:

```php
public function copy(): self
{
	return $this->rebind(SourceMap::empty());
}
```

Nested `SelectQuery`s reached through the AST (`ExistsCondition`, `SubqueryExpression`, subquery `IN`, etc.) are copied when those nodes call `$nested->rebind($sources)`. There is no second algorithm. The work is to freeze that contract, close ownership gaps that matter for reshape, then move count onto it.

## Non-goals

- Replacing `SourceMap` or identity-based anchors.
- Path-based remounting / `bindTo()`.
- Snapshot-and-restore mutation of the caller's query for count.
- A generic SQL AST or ON\Data-owned SQL compiler (see ADR 0001).
- Changing relation-loading semantics for normal `fetchAll` / `fetchOne`.
- Making derived `FROM` always deep-copied unless a concrete consumer needs it (see R4).
- Guaranteeing count on executors that cannot translate aggregates or derived sources (see R6 / D6).

## Design

### D1 — One cascade, two entry points

| API | Meaning |
| --- | --- |
| `copy()` | Cascade with no outer anchors (`SourceMap::empty()`). |
| `rebind(SourceMap $sources)` | Same cascade, starting with caller anchors (loader `relation → query`, outer correlation, transplant). |

`copy()` is the product-facing name for “deep remount this query.” `rebind()` remains the primitive used by loaders, nested nodes, and advanced callers. Implementation may keep `copy() → rebind(empty)` or invert naming internally; behavior must stay one pipeline.

### D2 — What the cascade owns

For a `SelectQuery` being rebound/copied:

1. Allocate a new `SelectQuery` shell (same executor binding).
2. Anchor `this → copy` in the working map (composed with any inherited anchors).
3. Allocate join counterparts and anchor each `Join` / join source.
4. Rebind selections, WHERE, join ON, GROUP BY, HAVING, ORDER BY through the map.
5. Rebind cached `RelationRef` shells onto the copy (structural resolve for nested relations under anchors), including selected/load configuration when present.
6. Copy limit / offset / result class / writable handler / alias metadata as today.

Nested queries are owned by the AST nodes that hold them. Those nodes **must** call `$query->rebind($sources)` (not share the old instance). That is the cascade into “children”; an explicit child-list on `SelectQuery` is optional sugar, not required if the AST walk is complete and tested.

`copy()` preserves product modes (`to(...)`, `writable(...)`, selected relations). Count must **not** execute that raw copy; it must normalize first (D5).

### D3 — Derived `FROM` policy

`new self($this->source, …)` **shares** a derived `SelectQuery` / collection source by reference. That is intentional, not accidental.

- Collection roots: sharing the collection definition is correct.
- Derived `SelectQuery` FROM: the cascade deeply remounts the query graph it **owns** (joins, AST-nested queries, relation shells, payloads) but treats an input derived query as shared input, not owned topology to clone.
- Count only reshapes the **outer** copy (and optional outer wrapper). It must not mutate the shared inner query's selections, filters, grouping, or alias.
- Deep-copying derived FROM is deferred: it adds complexity without a current consumer. Revisit only when a concrete caller needs an independent inner.

**Contract tests (required):**

- `copy()` on a query whose FROM is another `SelectQuery` keeps the **same** derived FROM instance (`assertSame`).
- After `count()` on such a query (when identity allows, or after exercising only the reshape path in a unit double), the inner query's selections, filters, grouping, and alias are unchanged.

### D4 — High-level `count()` (pipeline)

`SelectQuery::count(): int` becomes:

1. `$countQuery = $this->copy();`
2. Apply **scalar count normalization** (D5) to `$countQuery` only.
3. Apply one of the **count shapes** (D7) to that normalized query (and possibly a private outer wrapper from D6).
4. Execute via ordinary `fetchOne()` on the final scalar query.
5. Read the mandatory aggregate alias (D6); missing/null row → `0`.
6. Return `(int)`.

Remove `QueryExecutorInterface::count()` and Cycle `translateCount()` (and test doubles’ `count()` stubs). Adapters only translate normal selects.

### D5 — Scalar count normalization (required before fetch)

Count must never run product `fetchOne()` on an unmodified copy. Private normalization on the count copy (and on any outer wrapper) establishes a scalar execution contract:

| Concern | Rule |
| --- | --- |
| Sorts / limit / offset | Cleared. |
| Result class | Cleared (`to(...)` must not apply). |
| Writable handler | Cleared (`writable(...)` must not prepare/track). |
| Cached `LoadRuntime` | Not reused; count queries start with `runtime === null` and must not rely on the caller's cache. |
| Relation **loading** | Mechanically suppressed (below). |
| Relation **references** for filters/joins | Retained. |

**Relation loading suppression (mechanical):**

`copy()` / `RelationRef::rebind()` re-apply `load()` / field selection onto counterparts when the original was selected. A later `fetchOne()` therefore still enters `LoadRuntime` relation loading unless normalized.

Normalization must:

1. Clear scalar/root selections that will be replaced by the count shape (as today for reshape).
2. Walk the copy's `RelationRef` tree and clear **selection/load intent** (`selected` / explicit field load state) so `getRelationSelections()` is empty after normalization.
3. **Keep** `RelationRef` object identity/cache, joined sources, and condition/sort config needed by WHERE / join ON / EXISTS correlation — only loading/export selection is stripped.

Do not rely on “ignore relations” documentation alone. After normalization, `getRelationSelections()->isEmpty()` must hold before fetch.

**Product-mode regressions (required):**

- `$q->to(SomeDto::class)->count()` returns the integer and does not instantiate DTOs.
- `$q->writable($handler)->count()` returns the integer and does not call writable prepare/track for the aggregate row.

### D6 — Outer query factory and aggregate alias

Grouped and composite shapes allocate an **outer** `SelectQuery` whose FROM is the reshaped inner `as(…)`. Only `copy()` / `rebind()` currently guarantee executor binding; `new SelectQuery($derived)` alone is not the count contract.

Private helpers (names illustrative):

```php
private function newBoundQuery(CollectionInterface|SelectQuery $source): self
{
	return new self($source, $this->executor);
}

private function countAggregateAlias(): string // '__count'
private function countDerivedAlias(): string  // 'count_rows'
```

Rules:

- Every count shape selects exactly one aggregate expression aliased `__count`.
- Derived inner sources used as FROM use the stable alias `count_rows` (matches current Cycle snapshots; keeps tests stable).
- Outer queries are created only via the bound factory so they share the original executor (or throw the same unbound error path as any other bound call when the receiver has no executor).
- Execution reads `$row['__count']` after `fetchOne()`.
- `fetchOne()` returning `null`, or a row without `__count`, or a null `__count` → treat as `0` (empty matching set / empty group set). Do not throw for “no rows.”

### D7 — Count shapes and root identity

`count()` means **matching root entities / groups**, not join-expanded SQL rows.

| Case | Shape |
| --- | --- |
| `GROUP BY` or `HAVING` present | Inner (normalized copy): select literal `1`, keep FROM/WHERE/joins/GROUP BY/HAVING, no order/limit. Outer (bound): `COUNT(*) AS __count` over `inner->as('count_rows')`. Counts groups after HAVING. Root PK not required. |
| Ungrouped, single-column root PK | Normalized copy: select `COUNT(DISTINCT pk) AS __count`; keep FROM/WHERE/joins. |
| Ungrouped, composite root PK | Inner: select literal `1`, keep FROM/WHERE/joins, `GROUP BY` every PK column. Outer: `COUNT(*) AS __count` over `inner->as('count_rows')`. |
| Ungrouped, **no usable root identity** | **Reject.** Throw `CountRequiresRootIdentityException`. Do **not** fall back to `COUNT(*)`. |

**Usable root identity:** root `FROM` is a `CollectionInterface` with a non-empty primary key, and each PK field resolves to a `FieldRef` on the count query (same notion as today's `rootPrimaryKeyFields()`).

**Why reject:** `COUNT(*)` after joins counts join-expanded rows, which contradicts “root rows.” An implicit fallback makes the answer query-shape-dependent. Callers who want raw result-row cardinality can write an explicit aggregate select; that is not `count()`.

Derived-FROM roots without a collection PK therefore cannot use `count()` until/unless a later API adds caller-supplied identity or a separately named result-row counter. Out of scope here.

**Exception (resolved):**

```php
final class CountRequiresRootIdentityException extends LogicException
{
	public static function forQuery(SelectQuery $query): self
	{
		return new self(
			'SelectQuery::count() requires a usable root identity. '
			. 'Count a collection-root query or project a root identity first.'
		);
	}
}
```

- Dedicated type under `ON\Data\Query\Exception` — not `UnsupportedQueryException`, not bare `InvalidArgumentException`.
- Extends `LogicException`: the query graph is valid; the requested operation is undefined without root identity.
- Factory `forQuery(SelectQuery $query)` keeps room for richer context later without forcing message formatting at call sites.
- Leaves headroom for other count-specific exceptions later without over-generalizing.

### D8 — Executor capability (honest contract)

Removing `QueryExecutorInterface::count()` removes duplicated *semantics*, not the need for adapters to translate the emitted shapes.

Count works when the bound executor can run ordinary selects that include:

- aggregate expressions (`COUNT`, `COUNT(DISTINCT …)`), and
- derived `FROM` (`SelectQuery::as(...)`) for grouped/composite wraps.

If an executor cannot translate those, count fails the same way a hand-built aggregate/derived query would. Document that in `query-model.md` / `bound-execution.md`. Do not claim “every executor supports count” without that qualifier.

### D9 — Why copy is safe for count now

The anti-pattern was:

```php
$filtered = query(sameCollection);
$filtered->where($originalCondition); // FieldRefs still owned by $original
```

A cascading `copy()` remounts topology and payloads. Count then mutates only the normalized copy (and optional outer). The caller's query is unchanged.

## Requirements

- **R1** `copy()` remains a deep remount: nested AST queries get new `SelectQuery` instances whose correlated / owned sources are remapped.
- **R2** `rebind($map)` is the same cascade with inherited anchors composed before local join/root pairs.
- **R3** `copy()` / `rebind()` preserve executor binding so the copy is executable when the original was.
- **R4** Derived FROM stays shared by design (`copy()` keeps the same FROM instance); count reshape must not mutate that shared inner query (selections, filters, grouping, alias). Contract tests lock both facts.
- **R5** `SelectQuery::count()` does not mutate the receiver.
- **R6** `SelectQuery::count()` does not call an executor-specific count API; it emits ordinary aggregate/derived selects and relies on normal translation.
- **R7** Count normalization clears order, limit, offset, result class, writable handler, and relation **load** selection; filters/joins/correlation that use relation sources remain valid.
- **R8** Count semantics match D7 (join multiplication does not inflate single-PK counts; composite dedupes; grouped counts preserve HAVING; no-identity ungrouped counts throw).
- **R9** Docs: `query-model.md` describes `copy()` / `rebind()` as one cascade; count documents high-level reshape, scalar normalization, identity requirement, and executor shape expectations.
- **R10** Architecture tests continue to forbid path remounting in `FieldRef` and keep `rebind(SourceMap)` as the remount API.
- **R11** Every executed count query exposes aggregate alias `__count`; missing/null → `0`.
- **R12** Outer count wrappers are created only through a private bound-query factory sharing the receiver's executor.
- **R13** Regressions cover `to(...)` and `writable(...)` receivers: count returns `int` without DTO materialization or writable prepare/track of the aggregate.
- **R14** Ungrouped count without usable root identity throws `CountRequiresRootIdentityException` with the remedy message above.

## Key technical decisions

- **KTD1** Do not introduce a second clone algorithm. Empty map vs non-empty map is the only difference between `copy` and `rebind`.
- **KTD2** Prefer copy-then-normalize/reshape over in-place snapshot/restore for count.
- **KTD3** Outer wrap for grouped / composite shapes uses `newBoundQuery($inner->as('count_rows'))`, never an unbound `new SelectQuery` and never a second unrelated collection root.
- **KTD4** Drop executor `count()` entirely once high-level path lands (no dual APIs).
- **KTD5** Literal `1` / `COUNT(*)` / `COUNT(DISTINCT …)` construction uses existing expression factories where possible so translators stay ordinary.
- **KTD6** Ungrouped count without usable root PK throws `CountRequiresRootIdentityException::forQuery($this)`; no `COUNT(*)` fallback.
- **KTD7** Stable aliases: derived `count_rows`, aggregate `__count`.
- **KTD8** Scalar count normalization is a dedicated private step, not “hope fetchOne ignores relations/modes.”
- **KTD9** Derived FROM is shared input, not owned cascade topology; deep-copy deferred until a real consumer appears.

## Implementation units

### U1 — Spec freeze + copy contract tests (blocking)

Lock the cascade contract with focused tests:

- `copy()` yields a new root; original unchanged.
- EXISTS / scalar subquery / subquery IN hold a different `SelectQuery` instance after `copy()`, with remapped correlation sources when present.
- Joins and join ON conditions remount.
- Nested relation fields under anchors still resolve structurally (existing rebind tests remain green).
- Copied bound query keeps the same executor instance.
- Copied query preserves `to` / `writable` / selected relations (proving why D5 is required).
- Derived FROM sharing: `copy()` keeps `assertSame($inner, $copy->getFrom())`; owned nested queries still remount.

Optional doc-only pass in this unit: clarify “children = AST-owned nested queries + joins + relation shells; input derived FROM is shared.”

### U2 — Scalar count execution + high-level `count()`

Implement private helpers on `SelectQuery`:

- scalar count normalization (D5),
- bound outer factory + aliases (D6),
- shape application (D7),
- public `count(): int` orchestration (D4).

Tests (in addition to migrating existing Cycle count regressions):

- selected relations on the original do not cause relation loader work during count (assert empty relation selections on the executed query and/or no loader side effects),
- `to(Dto::class)->count()` and `writable(...)->count()` stay scalar (`R13`),
- ungrouped query without PK throws `CountRequiresRootIdentityException`,
- count on a derived-FROM outer leaves the shared inner's selections/filters/grouping/alias unchanged (`R4`),
- empty match → `0` (null `fetchOne`),
- recording executors observe only normal `fetchOne`/`fetchAll` of aggregate/derived selects — never `count()`.

Add `CountRequiresRootIdentityException` under `src/Query/Exception/`.

Migrate Cycle regressions (`Count*`, EXISTS count, join multiplication, composite+badges, grouped HAVING) onto the new path.

### U3 — Remove executor count surface

- Delete `QueryExecutorInterface::count()`.
- Delete `CycleQueryExecutor::count()` / `CycleQueryTranslator::translateCount*` helpers.
- Strip `count()` from all test executor doubles.
- Update `bound-execution` / `query-model` docs (including executor shape expectation and no-PK throw).

### U4 — Cleanup

- php-cs-fixer on touched files.
- `composer check` / focused Cycle + Query suites green.

## Acceptance examples

```php
// Caller unchanged; count uses a remounted, normalized copy internally.
$users->where(x()->exists($users->relatedQuery($users->posts)));
$total = $users->count();
self::assertNotSame([], $users->getConditions());
self::assertSame(/* previous selections */, $users->getSelections());

// Join multiplication does not inflate.
$users->join($users->posts);
self::assertSame($expectedRootRows, $users->count());

// Grouped HAVING counts groups, not flattened rows.
$q->groupBy(...)->having(...);
self::assertSame($expectedGroups, $q->count());

// Product modes stay scalar.
self::assertSame($expectedRootRows, $users->to(UserDto::class)->count());
self::assertSame($expectedRootRows, $users->writable($handler)->count());

// No root identity → fail closed.
$this->expectException(CountRequiresRootIdentityException::class);
$derivedRoot->count();
```

Fake executors used in unit tests only need `fetchOne` / `fetchAll` / `iterate`. A recording executor should see a select-shaped query (aggregate selection or derived FROM), never a dedicated count call.

## Open questions

All previously open items are resolved:

1. Derived alias / aggregate alias → `count_rows` / `__count` (KTD7).
2. No-PK fallback → reject via `CountRequiresRootIdentityException` (KTD6 / D7).
3. Derived FROM deep copy → **defer**; share input derived FROM; lock with contract tests (D3 / KTD9 / R4).
4. Exception shape → `CountRequiresRootIdentityException extends LogicException` + `forQuery()` + remedy message (D7).

## Success criteria

- One cascade story for remounting (`copy` / `rebind`).
- `count()` is query-model logic + scalar normalization + normal fetch.
- Mechanical suppression of relation loading and product result modes on the count path.
- Shared derived FROM is intentional and tested; count does not mutate it.
- No `QueryExecutorInterface::count()`.
- No silent `COUNT(*)` when root identity is missing; dedicated catchable exception instead.
- Existing Cycle count regressions remain green under the new path.
- Docs match the implementation, including executor shape expectations.
