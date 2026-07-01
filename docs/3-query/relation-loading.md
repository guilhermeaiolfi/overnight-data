# Relation Loading

`SelectQuery::load()` is the explicit relation-loading API. `SelectQuery::select()` also accepts `RelationRef` for convenience so existing queries can describe both flat root selections and nested relation branches.

## Selecting relations

Selecting a relation marks the terminal relation as loaded and visible:

```php
$u->load($u->posts);

$u->select($u->posts);
```

Nested traversal works through dynamic relation refs:

```php
$u->select($u->posts->author);
```

That automatically registers missing ancestors. In this example:

- `posts` becomes a visible structural container;
- `author` becomes the loaded terminal relation.

## Load options

The current public API on `RelationRef` is:

- `load(bool $load = true)`
- `visible(bool $visible = true)`
- `hidden()`
- `fields(string|FieldRef|array ...$fields)`
- `where(ConditionInterface ...$conditions)`
- `orderBy(Sort ...$sorts)`
- `strategy(?LoadStrategy $strategy)`
- `join()`
- `separate()`

Examples:

```php
$u->select(
    $u->posts->fields('id', 'title'),
    $u->posts->comments->fields('id', 'body'),
);

$u->load(
    $u->posts
        ->fields('id', 'title')
        ->where(x()->eq($u->posts->published, true))
        ->orderBy($u->posts->createdAt->desc())
        ->separate(),
);

$u->select($u->posts->load()->author);
$u->select($u->posts->hidden()->author);
```

`fields(...)` loads the relation and restricts public relation fields to the listed field names.

`where(...)` and `orderBy(...)` configure the relation query. Built-in loaders apply these options to separate-query relation loading. Joined relation loading rejects relation-level conditions and ordering for now because those options can change root row filtering or row order in surprising ways.

`strategy(null)` clears an explicit strategy override and falls back to the loader default. `join()` and `separate()` are convenience wrappers for `strategy(LoadStrategy::JOIN)` and `strategy(LoadStrategy::SEPARATE_QUERY)`.

## Visible and hidden branches

Each selected path segment has two result-shape flags:

- `load`: expose that relation's own fields
- `visible`: keep that relation as a public nested container

Defaults for traversed intermediate segments are:

- `load = false`
- `visible = true`

Hidden intermediate segments promote their visible descendants to the nearest visible ancestor. If the hidden segment is plural, promoted descendants stay plural.

A hidden terminal relation is rejected.

## Field restriction rules

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

Repeated selections of the same logical relation path merge:

- `load` and `visible` keep the permissive existing behavior;
- if any selection leaves fields unrestricted, the merged selection becomes unrestricted;
- conditions and sorts append in stable order;
- matching or single-sided strategy overrides are kept;
- conflicting strategy overrides for the same path are rejected.

## Result shapes

Built-in structured relation loading currently projects:

- `BelongsTo`: nested record or `null`
- `HasOne`: nested record or `null`
- `HasMany`: list of nested records or `[]`
- `M2M`: list of target records or `[]`

When a loaded relation does not specify `fields(...)`, the public projection uses that relation collection's visible fields.

Built-in `M2M` loading uses a loader-owned through-table shape internally. The parser/runtime keeps the through row and target row distinct, then projects the target child as the public relation payload. Internal through fields are not exposed in the final array result.

## Execution model

Built-in loaders currently default to:

- `BelongsToLoader`: `JOIN`
- `HasOneLoader`: `JOIN`
- `HasManyLoader`: `SEPARATE_QUERY`
- `M2MLoader`: `SEPARATE_QUERY`

The acquisition strategy is loader-owned. A relation selection may override the strategy, but relation selection still controls result shape while strategy changes SQL acquisition. Unsupported strategy/option combinations are rejected by the loader.

`fetchAll()` and `fetchOne()` support structured relation loading. `iterate()` does not.

## Architecture guardrails

- The registry must not know relation-specific loading behavior.
- Generic query and runtime code may contain relation-aware plumbing, but not relation-specific join rules.
- Relation loaders own relation-specific query construction details.
- Relations remain class-based and pluggable.
- SQL dialect differences should be delegated to Cycle Database or Doctrine DBAL rather than hand-coded in the data layer.
- The data layer must remain useful without the optional ORM.

## Current limits

- Structured loading is not the same as arbitrary related-field projection in flat scalar selections.
- Structured loading for built-in `FirstOfMany` is not implemented yet.
- Joined structured loading for built-in `M2M` is not implemented yet.
- Relation-level `where` and `orderBy` are supported for separate-query loading first; joined relation conditions and ordering are rejected by built-in loaders.
- Future relation branch configuration should stay loader-owned and branch-local rather than moving relation-specific rules into the registry or generic runtime.
