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
- `RelationRef`

Scalar expressions become flat selections. A direct `SelectQuery` is normalized to a `SubqueryExpression`.

Selecting a `RelationRef` participates in structured relation loading. The current relation-loading surface is documented in [`relation-loading.md`](./relation-loading.md).

```php
$u->select(
    $u->id,
    $u->email->as('contact_email'),
    $postCountQuery,
);
```

Use `all()` for a source-wide selection:

```php
$posts->select($posts->all());
```

`star()` remains as the backward-compatible alias for the same selection expression.

`require()` records an implicit selection with a tag and is used by internal query assembly when fields must be present without becoming caller-facing API.

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

`SelectQuery::as()` turns a query into a derived source for use in another query:

```php
$ranked = $inner->as('ranked_posts');

$query = query($ranked)
    ->select($ranked->all())
    ->where($ranked->field('__rank')->eq(1));
```

Omitting the alias is allowed; the backend assigns a stable internal alias. Derived sources expose `field()` and `all()`, but they do not expose relation loading.

## Inspecting the model

Use getters to inspect the built query:

- `getCollection()`
- `getSelections()`
- `getRelationSelections()`
- `getConditions()`
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
