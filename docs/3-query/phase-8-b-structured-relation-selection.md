# Phase 8B: Structured Relation Selection

Phase 8B connects relation selections to the Phase 8A structural parser so one `select()` call can describe both flat root fields and nested relation branches.

## Public API

`SelectQuery::select()` now accepts `RelationRef` in addition to scalar expressions:

```php
$users->select(
    $users->id,
    $users->name,
    $users->posts,
    $users->posts->author,
);
```

Relation selections describe result shape, not necessarily SQL join shape.

This means:

- scalar expressions still produce flat root values
- related field expressions such as `$users->posts->title` remain flat
- `RelationRef` selections produce nested arrays
- nested selections automatically register missing ancestors

Example:

```php
$users->select($users->posts->author);
```

Behaves structurally like:

```php
$users->select(
    $users->posts,
    $users->posts->author,
);
```

Repeated relation selection is idempotent, and every selected relation must belong to the same `SelectQuery`.

## Root Projection Rules

Relation selections do not count as scalar root selections.

When only relations are selected, the query keeps its default root projection and adds the nested branches:

```php
$users->select($users->posts);
```

Returns the usual root fields plus `posts`.

When scalar selections are present, the root result keeps only those explicit scalar selections plus the selected relation containers. Internally required root keys used for loading are removed before the final result is returned.

## Built-In Strategies

Phase 8B ships with loader-owned defaults:

- `BelongsToLoader` uses `LoadStrategy::JOIN`
- `HasOneLoader` uses `LoadStrategy::JOIN`
- `HasManyLoader` uses `LoadStrategy::SEPARATE_QUERY`

The strategy affects query execution and parser-node attachment, but not node creation:

- `BelongsTo` creates `SingularNode`
- `HasOne` creates `SingularNode`
- `HasMany` creates `CollectionNode`

## Mixed Nested Loading

Nested branches can mix joined and separate-query execution.

Example:

```php
$users->select(
    $users->posts,
    $users->posts->author,
);
```

With built-in defaults:

- `posts` executes as one related query
- `author` joins inside that related posts query

The logical relation path still belongs to the original root query, but nested joins are rebased onto the query where they actually execute.

## Result Shapes

Built-in relation result containers follow these shapes:

- `BelongsTo`: nested record or `null`
- `HasOne`: nested record or `null`
- `HasMany`: list of nested records or `[]`

Related rows currently include the target collection's full field set in storage representation.

## Composite Keys

Composite primary keys and composite relation references are supported for:

- root identity folding
- parent-child mounting
- separate-query predicates

Phase 8B preserves declared key order and does not depend on `ON\Data\Key` inside parser nodes.

## Current Restrictions

Phase 8B intentionally does not add:

- public strategy overrides
- `SelectQuery::load()`
- structured loading for `M2M`
- structured loading for `FirstOfMany`
- relation-level `where`
- relation-level `orderBy`
- nested related-field projection
- lazy loading
- mapper or hydration integration

Structured relation selection currently supports `fetchAll()` and `fetchOne()`. `iterate()` is rejected while separate-query loading still requires a complete parent batch.
