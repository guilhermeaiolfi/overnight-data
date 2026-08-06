# Query Model

`ON\Data\Query` provides a database-independent read-query model centered on `SelectQuery`.

It models intent only. SQL translation, backend-specific semantics, and execution strategy stay outside the core query model.

## Constructing a query

Use the helper for an unbound query:

```php
use function ON\Data\Query\query;
use function ON\Data\Query\x;

$u = query($users);

$u
    ->select($u->id, $u->name)
    ->where(x()->eq($u->active, true));
```

You can also pass a callback. Its return value is ignored.

```php
$u = query($users, fn ($query) => $query->select($query->id));
```

## Query-owned references

`SelectQuery` owns query-scoped references:

- `$query->field('id')` or `$query->id` returns a cached root `FieldRef`.
- `$query->relation('posts')` or `$query->posts` returns a cached root `RelationRef`.
- `$query->posts->title` returns a related `FieldRef`.
- `$query->posts->author` returns a nested `RelationRef`.

References are cached per query and per path. Two different queries over the same definition do not share `FieldRef` or `RelationRef` objects.

## Selecting values

`select()` accepts:

- `ValueExpressionInterface`
- `AliasedExpression`
- `SelectQuery`
- `StarExpression`
- `RelationRef` — marks that relation branch for nested loading (not a flat SQL column)

A bare `$u->posts` is equivalent to `$u->posts->load()` (all visible related fields). A configured ref keeps its options:

```php
$u->select(
    $u->id,
    $u->email->as('contact_email'),
    $u->posts,
    $u->profile->select('avatar'),
    $postCountQuery,
);
```

Relation-only `select($u->posts)` keeps the default root field selection. Passing any scalar/value expression clears defaults and uses the explicit scalar list, as before.

Scalar expressions become flat selections. A direct `SelectQuery` is normalized to a `SubqueryExpression`.

Use `all()` for a source-wide selection:

```php
$posts->select($posts->all());
```

`star()` remains as the backward-compatible alias for the same selection expression.

`require()` records an implicit selection with a tag and is used by internal query assembly when fields must be present without becoming caller-facing API.

## Result export

By default, bound queries return arrays. Object export is opt-in through `to(...)`. Bound execution starts from `DataRuntime` (for example via `CycleRuntimeFactory`):

```php
$rows = $runtime->query($users)->fetchAll();
// list<array<string, mixed>>

$row = $runtime->query($users)->fetchOne();
// array<string, mixed>|null

foreach ($runtime->query($users)->iterate() as $row) {
    // array<string, mixed>
}

$u = $runtime->query($users);

$objects = $u
    ->select($u->id, $u->name)
    ->to(stdClass::class)
    ->fetchAll();
// list<stdClass>
```

Read-only public-property class export:

```php
final class UserRow
{
    public int $id;
    public string $name;
}

$u = $runtime->query($users);

$rows = $u
    ->select($u->id, $u->name)
    ->to(UserRow::class)
    ->fetchAll();
// list<UserRow>
```

Public-property class export requirements:

- `stdClass` is supported.
- User-defined public-property classes are supported for read-only export.
- Public result keys must match public properties (or constructor/promoted parameters via the mapper).
- Nested typed object properties may be materialized into their declared classes when supported.
- Array relation/list properties annotated as `@var list<stdClass>` (or another item class) receive arrays of those items; bare `array` properties keep nested arrays.
- Writable export supports `stdClass` and concrete mutable public-property classes (not readonly). Selection aliases are the schema-path contract: DTO properties / `MapFrom` must match those keys; avoid `MapTo` renaming them for sync reads.

Read-only object export supports lazy iteration through `to(...)->iterate()`. `writable(...)->iterate()` is intentionally unsupported.

Writable export tracks query provenance in a `Session` on the same object instance returned to the caller:

```php
$session = new Session($commandExecutor);

$u = $runtime->query($users);

$user = $u
    ->select($u->id, $u->company->name->as('name'))
    ->to(UserRow::class) // or stdClass::class
    ->writable($session)
    ->fetchOne();
```

See [`bound-execution.md`](./bound-execution.md) for the full result-mode table and execution boundaries.

## Targeted results and default root fields

A `SelectQuery` with `to(...)` and no explicit `select()` selects the root collection's default scalar fields. The default applies only to the root collection; it does not auto-load relations.

Explicit `select(...)` disables default root field selection:

```php
$u = $runtime->query($users);

$rows = $u
    ->select($u->id, $u->name)
    ->to(UserRow::class)
    ->fetchAll();
```

The runtime may still include hidden required fields for identity or writable projection tracking. Selections tagged `SelectionTag::INTERNAL` are stripped from public array and object results.

Relation loading remains explicit through `RelationRef` branches. Prefer including them in `select()`:

```php
$u = $runtime->query($users);

$users = $u
    ->select(
        $u->id,
        $u->name,
        $u->posts->select('id', 'title'),
    )
    ->to(stdClass::class)
    ->fetchAll();
```

Configuring the ref before `select()` is equivalent. Do not introduce `with()` or `EntityQuery`.

## Named expressions

Query-local aliases are registered only after an aliased expression is successfully selected.

```php
$u->select($u->name->upper()->as('title'));

$u->get('title');
```

`get()` returns the underlying expression, not an alias-reference node.

## Joins and sources

`SelectQuery::join()` creates a neutral `Join` node:

```php
$company = $u->join($companies, name: 'company');
$company->on(x()->eq($u->companyId, $company->id));
```

`FieldRef` always belongs to a `QuerySourceInterface`, so root fields, joined fields, and relation-owned fields all expose their real source.

## Derived sources

`SelectQuery::as()` returns a `DerivedSelectQuery` wrapper for use in another query.
It does not mutate the inner `SelectQuery`, which remains available for building and
compiling the subquery:

```php
$ranked = $inner->as('ranked_posts');

$query = query($ranked)
    ->select($ranked->all())
    ->where($ranked->field('__rank')->eq(1));
// same as: ->where($ranked->__rank->eq(1));
```

Omitting the alias is allowed; the backend assigns a stable internal alias. Derived sources expose projected columns via `field()` and `all()` (and the same magic UX as roots: `$ranked->__rank` routes to `field('__rank')`). They do not expose relation loading — `$ranked->posts` fails unless `posts` is a projected selection key.

## Copying and source identity

Query copies preserve source ownership by rebinding expressions through an internal, immutable `SourceMap`. A source is identified by its object identity, not by its definition name or path: two queries over `users` are different sources.

`SelectQuery::copy()` is the product-facing deep remount (`rebind(SourceMap::empty())`). `rebind(SourceMap)` is the same cascade with outer anchors (loaders, correlation, transplants). Owned topology remounts: joins, relation shells, and nested queries held by the AST (EXISTS, scalar subqueries, subquery `IN`). An input derived `FROM` `DerivedSelectQuery` is shared by reference, not deep-copied.

Explicit map pairs are anchors. Under an anchored query or parent, unmapped `RelationRef` descendants resolve to the same-named cached relation on that counterpart (`relation($name)`). Only sources with no anchored ancestor remain unchanged. The map does not invent relations from field-path strings; structural resolve walks already-owned parent links.

## Counting matching rows

Use `SelectQuery::count()` when you need the number of matching root rows (or groups) for the current filters:

```php
$total = $users
    ->where(x()->exists($users->relatedQuery($users->posts)))
    ->count();
```

Count copies the query, normalizes that copy for scalar execution (clears order/limit/offset, result class, writable tracking, and relation **load** selection), and keeps joins already on the live query. The receiver is not mutated. `count()` answers the live query’s root/group cardinality, not a privately scrubbed reshape.

- Ungrouped collection roots with a single-column primary key use `COUNT(DISTINCT pk)`.
- Composite primary keys are deduplicated via a derived `SELECT 1 … GROUP BY` all PK columns, then outer `COUNT(*)`.
- `GROUP BY` / `HAVING` wrap a `SELECT 1` grouped subquery and count remaining groups.
- Ungrouped queries without a usable root primary key throw `CountRequiresRootIdentityException` (no silent `COUNT(*)` fallback).

There is no executor-specific count API. Bound executors must translate ordinary aggregate expressions and derived `FROM` selects the same way they would for a hand-built query.

## Inspecting the model

Use getters to inspect the built query:

- `getCollection()`
- `getSelections()`
- `getRelationSelections()`
- `getConditions()` / `getConditionList()` — tagged WHERE list (`USER`, `CORRELATION`, …)
- `getGroups()`
- `getHavingConditions()`
- `getSorts()`
- `getJoins()`
- `getLimit()`
- `getOffset()`

## Boundaries

- The query model is database-independent.
- Query objects may be relation-aware, but relation-specific join rules stay inside relation loaders.
- Built-in loaders own whether a relation branch uses joins or separate queries during structured loading.
- SQL dialect handling should be delegated to backend adapters such as Cycle Database or Doctrine DBAL rather than hand-coded in the query model.
