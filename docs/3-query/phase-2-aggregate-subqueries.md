# Phase 2 Aggregate And Subquery Expressions

Phase 2 extends `ON\Data\Query` with aggregate expressions and nested-query operands while keeping the model mutable, database-independent, and limited to intent representation.

It still does not compile SQL, execute queries, resolve relations, or validate whole-query semantics.

## Query Star And Row Counts

Each `SelectQuery` owns one cached star expression:

```php
$p = query($posts);

$p->star() === $p->star();
```

The star is not selectable by itself. Its Phase 2 job is row counting:

```php
$p->select(
	$p->star()->count()->as('total'),
);
```

The universal form is equivalent:

```php
$p->select(
	x()->count($p->star())->as('total'),
);
```

## Field Aggregates

Aggregateable value expressions expose fluent shorthands:

```php
$p->select(
	$p->id->count()->as('post_count'),
	$p->userId->countDistinct()->as('author_count'),
	$p->amount->sum()->as('total_amount'),
);
```

Those all delegate to the same `ExpressionFactory` methods:

```php
$p->select(
	x()->count($p->id)->as('post_count'),
	x()->countDistinct($p->userId)->as('author_count'),
	x()->sum($p->amount)->as('total_amount'),
);
```

`SUM` accepts one value expression. If a future query needs to sum a per-row combination such as subtotal plus tax, that combined row expression should exist first and then be aggregated.

## Scalar Subqueries

Nested `SelectQuery` instances can appear in value positions. Passing a query into `select()` normalizes it into a `SubqueryExpression` automatically:

```php
$u = query($users);
$p = query($posts, fn ($query) => $query
	->select($query->id->count())
);

$u->select(
	$u->id,
	$p,
);
```

You can also alias a query directly:

```php
$u->select(
	query($posts, fn ($p) => $p
		->select($p->id->count())
		->where(x()->eq($p->userId, $u->id))
	)->as('post_count'),
);
```

The subquery expression keeps the exact mutable `SelectQuery` object. If the nested query is changed later, the parent expression still points at that same query instance.

## Correlated References

Correlation is represented structurally through query-owned `FieldRef` objects:

```php
$u = query($users);
$p = query($posts);

$p->where(
	x()->eq($p->userId, $u->id),
);
```

The inner and outer field references keep different query owners. Phase 2 stores that shape but does not yet validate scope rules.

## Subqueries In Comparisons

Comparison operands now accept nested queries anywhere a value expression is valid:

```php
$u->where(
	x()->eq(
		$u->lastPostId,
		query($posts, fn ($p) => $p
			->select($p->id)
			->where(x()->eq($p->userId, $u->id))
		),
	),
);
```

```php
$u->where(
	x()->gt(
		query($posts, fn ($p) => $p
			->select($p->star()->count())
			->where(x()->eq($p->userId, $u->id))
		),
		5,
	),
);
```

## Exists Conditions

`EXISTS` and `NOT EXISTS` retain the exact nested query and do not auto-add projections:

```php
$u->where(
	x()->exists(
		query($posts, fn ($p) => $p
			->where(x()->eq($p->userId, $u->id))
		),
	),
);
```

## In Conditions

`IN` accepts either a non-empty literal/expression list or a subquery:

```php
$u->where(
	x()->in($u->status, ['active', 'pending']),
);
```

```php
$u->where(
	x()->in(
		$u->id,
		query($posts, fn ($p) => $p
			->select($p->userId)
			->where(x()->eq($p->published, true))
		),
	),
);
```

Empty lists, `null` list members, aliased expressions, and stars in `IN` sets are rejected immediately.

## Deferred Semantics

Phase 2 intentionally does not enforce whole-query semantic rules such as:

- whether a scalar subquery selects exactly one value;
- whether `IN` subqueries select one value;
- whether aggregate and non-aggregate selections require grouping;
- whether a correlated field reference is in a valid scope.

Those checks belong at a future planning or compilation boundary, not on this mutable builder model.
