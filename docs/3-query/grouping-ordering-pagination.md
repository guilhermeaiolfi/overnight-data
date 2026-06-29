# Grouping, Ordering, and Pagination

`SelectQuery` stores grouping, `HAVING`, sorting, and limit/offset pagination directly on the query object.

## Grouping

Use `groupBy()` with value expressions or nested queries:

```php
$u->groupBy(
    $u->status,
    $u->get('normalized_name'),
);
```

- Repeated calls append.
- A direct `SelectQuery` is normalized to `SubqueryExpression`.
- Named expressions returned by `get()` can be grouped because they resolve to the underlying expression object.

## HAVING

Use `having()` with condition objects:

```php
$u->having(
    x()->gt($u->get('total'), 10),
    x()->lt($u->amount->sum(), 1000),
);
```

- Repeated calls append.
- Top-level `HAVING` conditions are interpreted as an implicit `AND`.
- `having()` is allowed even without `groupBy()`.

## Sorting

Sort entries are explicit `Sort` objects with an expression and direction.

```php
$u->orderBy(
    $u->get('total')->desc(),
    $u->status->asc(),
);
```

You can also build sorts through `x()`:

```php
$u->orderBy(
    x()->asc($u->name),
    x()->desc($postCountQuery),
);
```

- Sort list order defines precedence.
- Repeated `orderBy()` calls append more sorts.
- A direct `SelectQuery` sort target becomes a `SubqueryExpression`.

## Pagination

Use `limit()` and `offset()` directly on the query:

```php
$u
    ->limit(25)
    ->offset(50);
```

- `0` is valid.
- `null` clears the current value.
- Negative values are rejected.
- Last call wins for each property independently.

## Deferred validation

The model stores query shape, but it does not yet validate every semantic rule around grouping, ordering, or backend pagination support.
