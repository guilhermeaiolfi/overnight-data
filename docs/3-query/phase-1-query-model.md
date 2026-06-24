# Phase 1 Query Model

`ON\Data\Query` adds a small, database-independent read-query model on top of the existing definition wrappers.

It does not execute queries, compile SQL, bind parameters, or traverse relations yet. This phase only models intent so later planners or adapters can consume it.

## Basic Construction

Use the external helper instead of adding query methods to definitions:

```php
use function ON\Data\Query\query;
use function ON\Data\Query\x;

$u = query($users);

$u
	->select(
		$u->id,
		$u->title,
		$u->email->as('contact_email'),
	)
	->where(
		x()->eq($u->active, true),
		x()->or(
			x()->isNull($u->deletedAt),
			x()->gt($u->deletedAt, new DateTimeImmutable('2026-01-01')),
		),
	);
```

`query()` always returns the created `SelectQuery`.

## Callback Construction

When a single expression reads better, pass a callback:

```php
$u = query($users, fn ($u) => $u
	->select($u->id, $u->title)
	->where(x()->eq($u->active, true))
);
```

Statement closures also work. Callback return values are ignored.

## Query-Scoped Fields

`SelectQuery` is both the builder and the owner of field references:

```php
$u->id;
$u->field('id');
```

Both forms return the same cached `FieldRef` for that query. Different queries over the same definition get different `FieldRef` objects, but each one still points at the same underlying `FieldInterface`.

Definitions remain the metadata authority. Query aliases and conditions do not write back into the registry definition array.

## Selections And Aliases

Selections are ordered and appended across repeated `select()` calls.

Use `->as()` on a value expression to create a query-local alias:

```php
$u->select($u->email->as('contact_email'));
```

Whitespace is trimmed. Empty aliases are rejected.

## Conditions

Repeated `where()` calls append to the top-level condition list. Those root conditions are implicitly combined with logical `AND`.

Nested groups stay explicit:

```php
$u->where(
	x()->or(
		x()->eq($u->active, true),
		x()->isNull($u->deletedAt),
	),
);
```

`ExpressionFactory` also supports `neq`, `gt`, `gte`, `lt`, `lte`, `and`, `or`, `not`, `isNull`, and `isNotNull`.

## Null Semantics

`eq(..., null)` and `neq(..., null)` normalize to explicit null-condition nodes:

```php
x()->eq($u->deletedAt, null);
x()->isNull($u->deletedAt);
```

Those forms are equivalent. Ordered comparisons reject `null`.

## Inspecting The Model

Consumers inspect the built query through getters:

```php
$u->getSource();
$u->getSelections();
$u->getConditions();
```

No SQL or execution layer exists in this phase.

## Deferred Direction

The current model intentionally leaves room for future scalar subqueries without implementing them yet:

```php
query($posts, fn ($p) => $p
	->select($p->id)
);
```

Later phases can extend the expression tree from this base once a real planner or compiler needs more structure.
