# Phase 7: Joins and Relation Loaders

Phase 7 introduces one neutral join model for the query layer and moves relation-join ownership into `ON\Data`.

## Query Sources

Every executable field source now implements `ON\Data\Query\QuerySourceInterface`:

```php
interface QuerySourceInterface
{
    public function getQuery(): SelectQuery;

    public function getCollection(): CollectionInterface;

    /**
     * @return list<string>
     */
    public function getPath(): array;

    public function field(string $name): FieldRef;
}
```

The canonical query sources are:

- `SelectQuery` for the root collection
- `Join` for explicit or relation-created joins
- `RelationRef` for logical relation paths

`SelectQuery::getCollection()` is the canonical root getter. The older query-root `getSource()` API does not exist in this phase.

## Field Ownership

`FieldRef` now belongs directly to a `QuerySourceInterface`:

```php
new FieldRef($source, $field);
```

Use `FieldRef::getSource()` to inspect where a field comes from.

Paths now come from the owning source:

- root field: `['id']`
- relation field: `['posts', 'title']`
- join field: `['company', 'name']`

## Explicit Joins

`SelectQuery::join()` creates a neutral `Join` node:

```php
$company = $users->join(
    $companies,
    JoinType::LEFT,
    'company',
);

$company->on(
    x()->eq($users->companyId, $company->id),
);
```

Join names are immutable and query-local. Duplicate join names are rejected.

Explicit join fields use the join path for default result names:

```php
$users->select($company->name);
// ['company.name' => 'Acme']
```

## Relation-Created Joins

`RelationRef` still represents the logical relation path, but the physical query source is created lazily only when a relation-owned field is translated.

That means relation fields can now participate in:

- `select`
- `where`
- `groupBy`
- `having`
- `orderBy`
- aggregates
- value operations

One `RelationRef` resolves its joined source only once per query, so repeated field usage reuses the same join objects.

## Loader Ownership

Relation loaders live under `ON\Data\Query\Relation\Loader`.

They are ON\Data-owned and stateless. They do not extend or wrap Cycle ORM relation loaders.

Every loader exposes two methods:

```php
public function join(RelationRef $relation): QuerySourceInterface;

public function load(RelationRef $relation, LoadRuntime $runtime): void;
```

In this phase:

- `join()` is implemented
- `load()` establishes the boundary and throws by default

`load()` is reserved for later nested relation loading and may eventually coordinate extra queries, dependent batching, and parser integration.

## Built-In Loader Defaults

Built-in relations declare their own loader defaults:

- `HasOneRelation` -> `HasOneLoader`
- `BelongsToRelation` -> `BelongsToLoader`
- `HasManyRelation` -> `HasManyLoader`
- `FirstOfManyRelation` -> `FirstOfManyLoader`
- `M2MRelation` -> `M2MLoader`

Custom relations can override the loader with `loader(CustomLoader::class)` as long as the class implements `LoaderInterface`.

## Flat Join Semantics

`join()` exposes flat rows.

That means:

- `HasMany` may multiply parent rows
- `M2M` may multiply parent rows
- no nested arrays are built
- no deduplication happens
- no automatic `DISTINCT` is added
- no pagination correction is attempted

Example:

```php
$users->select($users->id, $users->posts->title);
```

Possible result:

```php
[
    ['id' => 1, 'posts.title' => 'A'],
    ['id' => 1, 'posts.title' => 'B'],
]
```

## M2M Joins

Many-to-many joins are expressed through two ordinary `Join` nodes:

```text
parent source
    -> tags@through
    -> tags
```

The terminal target join is returned to relation field translation, but the backend translator still only sees neutral query nodes.

## Current Limits

This phase intentionally does not implement:

- nested relation join execution
- nested relation loading
- `SelectQuery::load()`
- collection folding or hydration
- `FirstOfMany` execution
- relation-level `where`
- relation-level `orderBy`
- M2M through-level `where`

Nested relation paths such as `posts.author.name` are rejected until a later bounded phase defines their semantics.
