# Relation Loading

Relation loading is configured through cached `RelationRef` branches. Pass a `RelationRef` to `SelectQuery::select()` to include nested relation results, or configure the ref before fetching. Root scalar expressions and relation refs can share one `select()` call.

## Selecting Relations

Any relation configuration that loads data marks that cached relation ref as selected. A bare `$u->posts` inside `select()` calls `load()` (all visible fields):

```php
$u->select($u->id, $u->name, $u->posts);

$u->select(
    $u->id,
    $u->posts->fields('id', 'title'),
);

$users->posts->load();

$users->posts->fields('id', 'title');

$users->posts->where(x()->eq($users->posts->published, true));

$users->posts->separate();

$u->posts
    ->fields('id', 'title')
    ->where(x()->eq($u->posts->published, true))
    ->orderBy($u->posts->createdAt->desc())
    ->separate();

$u->profile
    ->fields('avatar')
    ->join();
```

Nested traversal works through dynamic relation refs:

```php
$u->posts->author->fields('id', 'name');
```

That automatically registers missing ancestors. In this example:

- `posts` becomes a visible structural container;
- `author` becomes the loaded terminal relation.

Root scalars and relations can be selected together, or configured separately:

```php
$u->select($u->id, $u->name, $u->posts->fields('title'));

// equivalent:
$u->posts->fields('title');
$u->select($u->id, $u->name);
```

Relation-only `select($u->posts)` keeps default root fields.

## Load Options

The public configuration API on `RelationRef` is:

- `load()`
- `visible(bool $visible = true)`
- `hidden()`
- `fields(string|FieldRef|array ...$fields)`
- `where(ConditionInterface ...$conditions)`
- `orderBy(Sort ...$sorts)`
- `limit(int $limit)`
- `offset(int $offset)`
- `strategy(?LoadStrategy $strategy)`
- `join()`
- `separate()`

`load()`, `fields(...)`, `where(...)`, `orderBy(...)`, `limit(...)`, `offset(...)`, and strategy helpers select/load the relation. `visible(...)` and `hidden()` control result shape for configured paths and intermediate traversal.

`load()` selects the relation with default public fields and the loader's default strategy. It does not change fields, conditions, sorts, strategy, or visibility.

`fields(...)` restricts public relation fields to the listed field names.

`where(...)` and `orderBy(...)` configure the relation query. Built-in loaders apply these options to separate-query relation loading. Joined relation loading rejects relation-level conditions and ordering for now because those options can change root row filtering or row order in surprising ways.

`limit(...)` and `offset(...)` are relation-local, not global root query pagination. Built-in `HasMany` separate-query loading applies them per parent row by ranking matching children inside each parent partition. `limit(...)` requires an integer `>= 1`; `offset(...)` requires an integer `>= 0`.

Built-in `HasMany` limit/offset also requires deterministic selection-level `orderBy(...)`. If no selection order is configured, the loader rejects the relation because per-parent ranking would be ambiguous. The loader appends any missing target primary-key fields as stable tie breakers.

`strategy(null)` clears an explicit strategy override and falls back to the loader default. `join()` and `separate()` are convenience wrappers for `strategy(LoadStrategy::JOIN)` and `strategy(LoadStrategy::SEPARATE_QUERY)`. Repeated strategy calls on the same cached ref use the latest call.

## Visible and Hidden Branches

Each selected path segment has two result-shape flags:

- `load`: expose that relation's own fields
- `visible`: keep that relation as a public nested container

Defaults for traversed intermediate segments are:

- `load = false`
- `visible = true`

Hidden intermediate segments promote their visible descendants to the nearest visible ancestor. If the hidden segment is plural, promoted descendants stay plural.

A hidden terminal relation is rejected.

## Field Restriction Rules

`fields(...)` accepts:

- field names as strings;
- `FieldRef` objects from the same relation path and same root query;
- list arrays containing those values.

The current rules are intentionally strict:

- the list cannot be empty;
- field names cannot be blank;
- associative arrays are rejected;
- unknown field names are rejected;
- `FieldRef` values from another query or another path are rejected;
- repeated field names are deduplicated in stable order.

Because relation refs are cached mutable branch proxies, repeated calls configure the same branch. Conditions and sorts append in call order; field lists are replaced by the latest `fields(...)` call; strategy uses the latest call.

## Result Shapes

Built-in structured relation loading currently projects:

- `BelongsTo`: nested record or `null`
- `HasOne`: nested record or `null`
- `HasMany`: list of nested records or `[]`
- `FirstOfMany`: nested record or `null`
- `M2M`: list of target records or `[]`

When a loaded relation does not specify `fields(...)`, the public projection uses that relation collection's visible fields.

Built-in `FirstOfMany` loading is separate-query-only. JOIN loading is intentionally unsupported because the loader must choose one ordered child per parent without changing root row shape. The relation definition must provide deterministic `orderBy` metadata; the loader appends any missing target primary-key fields as stable tie breakers. SQL backends with the modeled window-expression and derived-source APIs load it by ranking children with `ROW_NUMBER() OVER (PARTITION BY child relation keys ORDER BY definition order, primary key tie breakers)` and filtering the derived source to rank `1`.

`FirstOfMany` is query-only: its default `persistencePlanner` is `null`. Mutations belong on the underlying has-many (or child collection), not on the first-of-many view.

Built-in `HasMany` limit/offset is also separate-query-only. JOIN loading with relation-level `limit(...)` or `offset(...)` is intentionally unsupported. Supported SQL backends load limited `HasMany` branches by wrapping the relation query in a derived source, adding `ROW_NUMBER() OVER (PARTITION BY child relation keys ORDER BY selection order, primary key tie breakers)`, and filtering that rank in the outer query.

Example:

```php
$users->posts
    ->fields('id', 'title')
    ->orderBy($users->posts->createdAt->desc())
    ->limit(3);
```

That means "top 3 posts per user", not "top 3 posts total".

Built-in `M2M` loading uses a loader-owned through-table shape internally. The parser/runtime keeps the through row and target row distinct, then projects the target child as the public relation payload. Internal through fields are not exposed in the final array result.

## Execution Model

Built-in loaders currently default to:

- `BelongsToLoader`: `JOIN`
- `HasOneLoader`: `JOIN`
- `HasManyLoader`: `SEPARATE_QUERY`
- `FirstOfManyLoader`: `SEPARATE_QUERY`
- `M2MLoader`: `SEPARATE_QUERY`

The acquisition strategy is loader-owned. A relation selection may override the strategy, but relation selection still controls result shape while strategy changes SQL acquisition. Unsupported strategy/option combinations are rejected by the loader.

`fetchAll()` and `fetchOne()` support structured relation loading. `iterate()` does not.

## Batch size and separate-query correlation

HasMany, M2M, and FirstOfMany default to `SEPARATE_QUERY`. After the parent/root batch is fetched, the loader correlates children with parent key values:

- simple keys become one `IN (...)` predicate over the parent key set;
- composite keys become an `OR` of per-parent `AND` key comparisons.

Built-in loaders chunk that parent-key set (default **100**, Doctrine-style) so a large parent page does not produce a single oversized `IN` / `OR` statement. Each chunk replaces the `ConditionTag::CORRELATION` predicate on the relation query; user `where()` conditions stay tagged `USER` and are untouched. Results merge into the same relation nodes.

Practical guidance:

- Prefer `join()` for shallow to-one or small to-many loads when the loader accepts JOIN for that relation and option set.
- Keep parent `fetchAll()` batches bounded when using separate-query HasMany/M2M/FirstOfMany (page roots); chunking bounds SQL size, not PHP memory for the full parent set.
- Nested separate-query branches multiply round-trips (one continuation query per loaded branch level per key chunk, not one query per parent row).
- Override batch size by providing a custom `AbstractLoader` subclass that overrides `separateQueryBatchSize()` (built-in loaders are final; no public RelationRef knob yet).

## Architecture Guardrails

- The registry must not know relation-specific loading behavior.
- Generic query and runtime code may contain relation-aware plumbing, but not relation-specific join rules.
- Relation loaders own relation-specific query construction details.
- Relations remain class-based and pluggable.
- SQL dialect differences should be delegated to Cycle Database or Doctrine DBAL rather than hand-coded in the data layer.
- The data layer must remain useful without the optional ORM.

## Current Limits

- Structured loading is not the same as arbitrary related-field projection in flat scalar selections.
- Structured loading for built-in `FirstOfMany` is implemented as separate-query loading. JOIN loading is intentionally unsupported, deterministic relation-level `orderBy` metadata is required, and supported SQL backends use windowed ranking internally.
- Structured loading for built-in `HasMany` supports relation-level `limit(...)` and `offset(...)` only in separate-query mode, applies them per parent, and requires deterministic selection-level `orderBy(...)`.
- Joined structured loading for built-in `M2M` is not implemented yet.
- Relation-level `where` and `orderBy` are supported for separate-query loading first; joined relation conditions and ordering are rejected by built-in loaders.
- Separate-query correlation uses `IN` (simple keys) or per-parent `OR`/`AND` (composite keys), chunked by parent-key batch (default 100); see [Batch size and separate-query correlation](#batch-size-and-separate-query-correlation).
- Future relation branch configuration should stay loader-owned and branch-local rather than moving relation-specific rules into the registry or generic runtime.
