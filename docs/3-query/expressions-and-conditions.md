# Expressions and Conditions

`ON\Data\Query` represents selections and predicates with explicit expression and condition objects instead of raw SQL fragments.

## Scalar expressions

The main scalar expression sources are:

- `FieldRef`
- `LiteralExpression`
- `RawSqlExpression`
- `SubqueryExpression`
- `ValueOperationExpression`
- `AggregateExpression`
- `WindowFunctionExpression`

`AliasedExpression` is selection-only. It wraps another value expression for `select()`, but it is not a general operand type.

## Aliases

```php
$u->select($u->email->as('contact_email'));
```

- Whitespace is trimmed.
- Empty aliases are rejected.
- Duplicate aliases in the same query are rejected.
- Alias registration is atomic across one `select()` call.

## Semantic operations

Use `x()` for the universal factory API:

```php
x()->upper($u->name);
x()->lower($u->email);
x()->concat($u->firstName, ' ', $u->lastName);
x()->coalesce($u->preferredName, $u->name, 'Unknown');
x()->add($u->subtotal, $u->tax);
```

Unary shorthands are also available:

```php
$u->name->upper();
$u->email->lower();
```

These operations are semantic. They do not encode SQL function names.

## Raw SQL escape hatch

`x()->rawSql()` creates an advanced escape-hatch expression for SQL features that are not modeled by the typed query API yet:

```php
$u->select(
    x()->rawSql('LOWER(name)')->as('lower_name'),
);
```

Raw SQL bypasses the typed query model. Parameter bindings are supported for values:

```php
$u->where(x()->eq(x()->rawSql('LOWER(name)'), 'ada'));
$u->where(x()->eq(x()->rawSql('name || ?', [' Lovelace']), 'Ada Lovelace'));
```

Do not concatenate user input into the SQL string. Parameters are for values only; identifiers inside the SQL fragment are not portable or automatically quoted. Prefer modeled expressions when they exist.

## Window functions

SQL functions that need richer modeling live under `x()->fn()`:

```php
$rank = x()->fn()
    ->rowNumber()
    ->over(
        partitionBy: $posts->userId,
        orderBy: $posts->createdAt->desc(),
    )
    ->as('rank');
```

Supported ranking functions:

- `rowNumber()` gives exactly one ordered position per row.
- `rank()` keeps ties with gaps.
- `denseRank()` keeps ties without gaps.

`over()` accepts a single expression or a list for `partitionBy`, and a single `Sort` or a list for `orderBy`:

```php
x()->fn()->rowNumber()->over(
    partitionBy: [$posts->tenantId, $posts->userId],
    orderBy: [$posts->createdAt->desc(), $posts->id->asc()],
);
```

Filtering by a window output requires a derived query source:

```php
$inner = query($posts)
    ->select($posts->all(), $rank);

$ranked = $inner->as('ranked_posts');

$topPerGroup = query($ranked)
    ->select($ranked->all())
    ->where($ranked->field('rank')->eq(1));
```

## Aggregates and subqueries

Supported aggregate builders are:

- `count()`
- `countDistinct()`
- `sum()`

Examples:

```php
$u->id->count();
$u->amount->sum();
$postCount = (new SubqueryExpression($posts))->as('post_count');
```

Direct nested queries are normalized to `SubqueryExpression` where the API accepts them, including `select()`, comparisons, grouping, and sorting.

## Comparisons and boolean composition

Use `x()` to build conditions:

```php
$u->where(
    x()->eq($u->active, true),
    x()->or(
        x()->isNull($u->deletedAt),
        x()->gt($u->deletedAt, new DateTimeImmutable('2026-01-01')),
    ),
);
```

Supported condition builders include:

- `eq`, `neq`, `gt`, `gte`, `lt`, `lte`
- `and`, `or`, `not`
- `isNull`, `isNotNull`
- `exists`, `notExists`
- `in`, `notIn`

Top-level repeated `where()` calls append conditions that are interpreted together later.

## Null and operand rules

- `eq(..., null)` normalizes to `isNull(...)`.
- `neq(..., null)` normalizes to `isNotNull(...)`.
- Ordered comparisons reject `null`.
- `AliasedExpression`, `StarExpression`, and `ConditionInterface` are rejected where a value operand is required.
- `StarExpression` is special-purpose: use `all()` for source-wide selection and `count()` for row counting.

## Deferred semantics

The expression model intentionally does not perform whole-query validation for:

- aggregate versus grouping compatibility;
- operation type correctness;
- backend-specific function support;
- subquery projection shape.

Those checks belong to the execution or translation boundary, not the query AST itself.
