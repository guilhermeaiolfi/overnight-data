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

`x()->rawSql()` is an advanced escape hatch for SQL features the typed query API does not model yet.

**Trust boundary:** the SQL string is trusted application code. It is sent to the backend as a fragment (identifiers and keywords are not quoted or validated). Treat it like writing SQL by hand.

```php
$u->select(
    x()->rawSql('LOWER(name)')->as('lower_name'),
);
```

Bind dynamic **values** with `?` placeholders. Parameters are escaped as values; they cannot safely carry identifiers (table/column names), operators, or SQL keywords.

```php
$u->where(x()->eq(x()->rawSql('LOWER(name)'), 'ada'));
$u->where(x()->eq(x()->rawSql('name || ?', [' Lovelace']), 'Ada Lovelace'));
```

Unsafe (do not do this):

```php
// User input must never be concatenated into the SQL string.
$column = $_GET['column']; // attacker-controlled
$u->select(x()->rawSql("LOWER({$column})"));
```

Prefer modeled expressions (`$u->name->lower()`, comparisons, aggregates, windows) whenever they exist.

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

Built-in `FirstOfMany` relation loading uses this same row-number-over-derived-query pattern internally: it partitions by the child-side relation keys, orders by the relation definition order plus primary-key tie breakers, and filters the derived source to rank `1`.

## Aggregates and subqueries

Supported aggregate builders are:

- `count()`
- `countDistinct()`
- `sum()`
- `avg()`
- `min()`
- `max()`

Examples:

```php
$u->id->count();
$u->amount->sum();
$u->amount->avg();
$u->amount->min();
$u->amount->max();

// factory form
x()->avg($posts->amount);
x()->min($posts->price);
x()->max($posts->score);

$postCount = (new SubqueryExpression($posts))->as('post_count');
```

`avg()`, `min()`, and `max()` follow the same validation as `sum()`: `AliasedExpression` operands and nested aggregates are rejected.

Direct nested queries are normalized to `SubqueryExpression` where the API accepts them, including `select()`, comparisons, grouping, and sorting.

## Pattern-matching conditions (LIKE)

Use `like()` and `notLike()` for SQL `LIKE` / `NOT LIKE` predicates:

```php
$u->where(x()->like($u->name, 'Ada%'));
$u->where(x()->notLike($u->email, '%@spam.%'));

// fluent shorthand
$u->where($u->name->like('G%'));
$u->where($u->name->notLike('%bot%'));
```

Convenience helpers wrap the plain string value with `%` automatically:

| Method | Translates to |
|---|---|
| `contains($expr, 'ac')` | `expr LIKE '%ac%'` |
| `notContains($expr, 'ac')` | `expr NOT LIKE '%ac%'` |
| `startsWith($expr, 'Ada')` | `expr LIKE 'Ada%'` |
| `endsWith($expr, 'ace')` | `expr LIKE '%ace'` |

```php
$u->where($u->name->contains('grace'));
$u->where($u->name->startsWith('Ada'));
$u->where($u->name->endsWith('ace'));
$u->where($u->email->notContains('@spam'));
```

**Note:** `%` and `_` inside the value passed to `like()` / `notLike()` / convenience helpers are treated as SQL wildcards. The caller is responsible for escaping them when needed. No automatic SQL `ESCAPE` clause is added.

Passing `null` as the pattern to `like()` or `notLike()` throws `InvalidArgumentException`, matching the behavior of ordered comparisons (`gt`, `gte`, etc.).

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
- `like`, `notLike`, `contains`, `notContains`, `startsWith`, `endsWith`
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
