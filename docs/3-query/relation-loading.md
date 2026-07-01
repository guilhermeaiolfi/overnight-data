# Relation Loading

`SelectQuery::select()` accepts `RelationRef` so one query can describe both flat root selections and nested relation branches.

## Selecting relations

Selecting a relation marks the terminal relation as loaded and visible:

```php
$u->select($u->posts);
```

Nested traversal works through dynamic relation refs:

```php
$u->select($u->posts->author);
```

That automatically registers missing ancestors. In this example:

- `posts` becomes a visible structural container;
- `author` becomes the loaded terminal relation.

## Selection options

The current public API on `RelationRef` is:

- `load(bool $load = true)`
- `visible(bool $visible = true)`
- `hidden()`
- `fields(string|FieldRef|array ...$fields)`

Examples:

```php
$u->select(
    $u->posts->fields('id', 'title'),
    $u->posts->comments->fields('id', 'body'),
);

$u->select($u->posts->load()->author);
$u->select($u->posts->hidden()->author);
```

`fields(...)` currently loads the relation and restricts public relation fields to the listed field names. There is no separate shorthand relation-field selector method in the current public API.

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

Repeated selections of the same logical relation path merge. If any selection leaves fields unrestricted, the merged selection becomes unrestricted.

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

The acquisition strategy is loader-owned. Relation selection controls result shape, not SQL shape.

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
- Public strategy overrides are not exposed yet.
- `SelectQuery::load()` is not exposed.
- Structured loading for built-in `FirstOfMany` is not implemented yet.
- Relation-level `where` and `orderBy` are not exposed in the current public relation-selection API.
- Future relation branch configuration should stay loader-owned and branch-local rather than moving relation-specific rules into the registry or generic runtime.
