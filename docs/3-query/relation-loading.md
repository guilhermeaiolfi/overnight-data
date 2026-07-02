# Relation Loading

Relation loading is configured through cached `RelationRef` branches. `SelectQuery::select()` is for root scalar/value expressions; relation branches are selected by configuring the relation ref directly.

## Selecting Relations

Any relation configuration that loads data marks that cached relation ref as selected:

```php
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

Root field selection remains separate:

```php
$u->posts->fields('title');
$u->select($u->id, $u->name);
```

## Load Options

The public configuration API on `RelationRef` is:

- `load()`
- `visible(bool $visible = true)`
- `hidden()`
- `fields(string|FieldRef|array ...$fields)`
- `where(ConditionInterface ...$conditions)`
- `orderBy(Sort ...$sorts)`
- `strategy(?LoadStrategy $strategy)`
- `join()`
- `separate()`

`load()`, `fields(...)`, `where(...)`, `orderBy(...)`, and strategy helpers select/load the relation. `visible(...)` and `hidden()` control result shape for configured paths and intermediate traversal.

`load()` selects the relation with default public fields and the loader's default strategy. It does not change fields, conditions, sorts, strategy, or visibility.

`fields(...)` restricts public relation fields to the listed field names.

`where(...)` and `orderBy(...)` configure the relation query. Built-in loaders apply these options to separate-query relation loading. Joined relation loading rejects relation-level conditions and ordering for now because those options can change root row filtering or row order in surprising ways.

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
- `M2M`: list of target records or `[]`

When a loaded relation does not specify `fields(...)`, the public projection uses that relation collection's visible fields.

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

## Architecture Guardrails

- The registry must not know relation-specific loading behavior.
- Generic query and runtime code may contain relation-aware plumbing, but not relation-specific join rules.
- Relation loaders own relation-specific query construction details.
- Relations remain class-based and pluggable.
- SQL dialect differences should be delegated to Cycle Database or Doctrine DBAL rather than hand-coded in the data layer.
- The data layer must remain useful without the optional ORM.

## Current Limits

- Structured loading is not the same as arbitrary related-field projection in flat scalar selections.
- Structured loading for built-in `FirstOfMany` is implemented as separate-query loading. JOIN loading is intentionally unsupported because the loader must choose one ordered child per parent.
- SQL backends may internally optimize `FirstOfMany` separate-query loading with a windowed per-parent limit built through the internal `rawSql()` escape hatch; this is not a public relation pagination feature.
- Built-in `FirstOfMany` requires deterministic relation-level `orderBy` metadata. The loader appends missing target primary-key fields as stable tie breakers.
- Joined structured loading for built-in `M2M` is not implemented yet.
- Relation-level `where` and `orderBy` are supported for separate-query loading first; joined relation conditions and ordering are rejected by built-in loaders.
- Future relation branch configuration should stay loader-owned and branch-local rather than moving relation-specific rules into the registry or generic runtime.
