# Expressions and Conditions

`ON\Data\Query` represents selections and predicates with explicit expression and condition objects instead of raw SQL fragments.

## Scalar expressions

The main scalar expression sources are:

- `FieldRef`
- `LiteralExpression`
- `SubqueryExpression`
- `ValueOperationExpression`
- `AggregateExpression`

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

## Aggregates and subqueries

Supported aggregate builders are:

- `count()`
- `countDistinct()`
- `sum()`

Examples:

```php
$u->id->count();
$u->amount->sum();
$postCount = $posts->as('post_count');
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
- `StarExpression` is special-purpose and currently only supports row counting.

## Deferred semantics

The expression model intentionally does not perform whole-query validation for:

- aggregate versus grouping compatibility;
- operation type correctness;
- backend-specific function support;
- subquery projection shape.

Those checks belong to the execution or translation boundary, not the query AST itself.
