# Phase 4 — Ordering, Grouping, HAVING, and Pagination

## Status

Architecture specification for review only.

Do not begin implementation until this specification is approved.

Do not begin planning, SQL compilation, execution, joins, relations, or later
query phases automatically after implementing this phase.

Implementation baseline:

```text
Repository: guilhermeaiolfi/overnight-data
Starting SHA: 1f3f09a10156757038dfcba81b8dfa548743864a
```

Keep the Phase 4 commit limited to query code, query tests, query
documentation, and the corresponding README update.

---

## 1. Purpose

The current query model can represent:

- selections;
- `WHERE` conditions;
- semantic value operations;
- aggregates;
- scalar and correlated subqueries;
- `EXISTS`;
- literal and subquery `IN`;
- query-local named expressions.

Phase 4 completes the remaining core read-query shape by adding:

1. ordering;
2. grouping;
3. `HAVING`;
4. limit and offset pagination.

The resulting database-independent query root becomes:

```text
SelectQuery
 ├── source
 ├── selections
 ├── WHERE conditions
 ├── group expressions
 ├── HAVING conditions
 ├── sort entries
 ├── limit
 └── offset
```

`SelectQuery` remains the mutable fluent builder and query model.

There is still no separate `SelectQuerySpec`, planner, compiler, validator, or
execution layer.

---

## 2. Guiding rule

Represent the requested query meaning with the smallest useful model.

Do not add infrastructure for future SQL generation.

In particular:

- store grouping as an ordered list of value expressions;
- store `HAVING` as an ordered list of conditions with implicit top-level
  `AND`;
- use one small sort node because ordering needs both an expression and a
  direction;
- store limit and offset directly on `SelectQuery`;
- reuse the current expression and subquery model;
- reuse query-local named expressions through `get()`;
- reject only obvious local argument errors;
- defer whole-query semantic checks to the future planning boundary.

---

## 3. Goals

Phase 4 must support:

```php
$u = query($users);

$u
    ->select(
        $u->status,
        $u->id->count()->as('total'),
    )
    ->where(
        x()->eq($u->active, true),
    )
    ->groupBy(
        $u->status,
    )
    ->having(
        x()->gt($u->get('total'), 10),
    )
    ->orderBy(
        $u->get('total')->desc(),
        $u->status->asc(),
    )
    ->limit(25)
    ->offset(50);
```

The model must preserve:

- group-expression order;
- `HAVING` condition order;
- sort precedence;
- the distinction between ascending and descending order;
- `limit(0)`;
- `offset(0)`;
- nullable limit and offset values.

No SQL is produced.

---

## 4. Non-goals

Do not implement:

- SQL compilation;
- query execution;
- query planning;
- a standalone validator;
- automatic aggregate/grouping validation;
- field-type validation;
- backend capability checks;
- joins;
- relation traversal;
- eager loading;
- result hydration;
- null-ordering controls;
- collation controls;
- random ordering;
- cursor pagination;
- keyset pagination;
- page-number helpers;
- `DISTINCT`;
- `DISTINCT ON`;
- rollup;
- cube;
- grouping sets;
- window functions;
- aliases as database-visible references;
- selection provenance;
- automatic internal selections;
- clearing all groups or sorts through dedicated methods.

Those concerns require separate review.

---

## 5. Public API shape

Add to `SelectQuery`:

```php
public function groupBy(
    ValueExpressionInterface|SelectQuery ...$expressions,
): self;

public function having(
    ConditionInterface ...$conditions,
): self;

public function orderBy(
    Sort ...$sorts,
): self;

public function limit(
    ?int $limit,
): self;

public function offset(
    ?int $offset,
): self;
```

Add to `AbstractValueExpression`:

```php
public function asc(): Sort;

public function desc(): Sort;
```

Add to `ExpressionFactory`:

```php
public function asc(
    ValueExpressionInterface|SelectQuery $expression,
): Sort;

public function desc(
    ValueExpressionInterface|SelectQuery $expression,
): Sort;
```

Add getters:

```php
/**
 * @return list<ValueExpressionInterface>
 */
public function getGroups(): array;

/**
 * @return list<ConditionInterface>
 */
public function getHavingConditions(): array;

/**
 * @return list<Sort>
 */
public function getSorts(): array;

public function getLimit(): ?int;

public function getOffset(): ?int;
```

Use `getSomething()` naming consistently.

---

## 6. Grouping

### 6.1 Storage

Add to `SelectQuery`:

```php
/**
 * @var list<ValueExpressionInterface>
 */
private array $groups = [];
```

No separate `Group` node is required because a grouping entry contains only
one value expression.

### 6.2 `groupBy()`

```php
public function groupBy(
    ValueExpressionInterface|SelectQuery ...$expressions,
): self;
```

Behavior:

1. require at least one argument;
2. normalize every direct `SelectQuery` into `SubqueryExpression`;
3. preserve supplied value expressions;
4. append the normalized expressions in order;
5. return the same query.

Repeated calls append:

```php
$u->groupBy($u->country);
$u->groupBy($u->city);
```

produces:

```text
[country, city]
```

### 6.3 Invalid group inputs

The public signature structurally excludes:

- `AliasedExpression`;
- `StarExpression`;
- conditions;
- raw PHP literals.

Callers may group by an explicit literal only by creating a value expression:

```php
$u->groupBy(
    x()->literal('constant'),
);
```

No special meaning is assigned to that shape.

### 6.4 Named expressions

Named-expression lookup returns the underlying semantic expression, so this is
valid:

```php
$u
    ->select(
        $u->name->upper()->as('normalized_name'),
    )
    ->groupBy(
        $u->get('normalized_name'),
    );
```

The group list contains the same `ValueOperationExpression`.

It does not contain a string alias reference.

### 6.5 Aggregates in grouping

Phase 4 does not add a recursive grouping validator.

The model can structurally receive an `AggregateExpression` because aggregate
expressions are value expressions.

Whether an aggregate or aggregate-containing operation is legal in `GROUP BY`
is checked later when planning or compilation consumes the complete query.

Do not add a partial rule that checks only some expression shapes.

---

## 7. HAVING

### 7.1 Storage

Add:

```php
/**
 * @var list<ConditionInterface>
 */
private array $havingConditions = [];
```

### 7.2 `having()`

```php
public function having(
    ConditionInterface ...$conditions,
): self;
```

Behavior:

- require at least one condition;
- append conditions in order;
- return the same query.

Repeated calls append.

### 7.3 Top-level semantics

As with `where()`, all top-level `HAVING` conditions are implicitly combined
with logical `AND`.

Do not create a redundant root `LogicalCondition`.

Example:

```php
$u->having(
    x()->gt($u->id->count(), 10),
    x()->lt($u->amount->sum(), 1000),
);
```

means:

```text
AND(
    COUNT(id) > 10,
    SUM(amount) < 1000
)
```

Nested groups continue using:

```php
x()->and(...)
x()->or(...)
x()->not(...)
```

### 7.4 Named expressions

A selected aggregate or operation may be reused:

```php
$u
    ->select(
        $u->amount->sum()->as('total'),
    )
    ->having(
        x()->gt(
            $u->get('total'),
            100,
        ),
    );
```

The condition contains the aggregate expression itself.

It does not assume the target database permits `HAVING total > 100`.

### 7.5 HAVING without grouping

Do not require `groupBy()` before `having()`.

Queries with one global aggregate may legitimately use `HAVING` without an
explicit group list.

Do not impose backend-specific restrictions here.

---

## 8. Ordering

### 8.1 Sort direction

Add:

```php
enum SortDirection: string
{
    case ASC = 'asc';
    case DESC = 'desc';
}
```

The enum represents semantic direction, not SQL text.

### 8.2 Sort node

Add:

```php
final class Sort
{
    public function __construct(
        ValueExpressionInterface $expression,
        SortDirection $direction,
    );

    public function getExpression(): ValueExpressionInterface;

    public function getDirection(): SortDirection;
}
```

The node is immutable after construction.

No alias, null-ordering, or collation metadata is added.

### 8.3 Storage

Add:

```php
/**
 * @var list<Sort>
 */
private array $sorts = [];
```

Sort list order defines precedence.

### 8.4 Expression-owned direction wrappers

Add to `AbstractValueExpression`:

```php
final public function asc(): Sort
{
    return x()->asc($this);
}

final public function desc(): Sort
{
    return x()->desc($this);
}
```

These methods do not mutate the expression. They create contextual `Sort`
wrappers:

```php
$u->name->asc();
$u->id->desc();
```

This matches the established expression-owned fluent style:

```php
$u->name->as('title');
$u->amount->sum();
```

### 8.5 Universal `x()` direction API

Add to `ExpressionFactory`:

```php
public function asc(
    ValueExpressionInterface|SelectQuery $expression,
): Sort;

public function desc(
    ValueExpressionInterface|SelectQuery $expression,
): Sort;
```

These forms are equivalent:

```php
$u->name->asc();
x()->asc($u->name);
```

A direct `SelectQuery` is normalized into `SubqueryExpression`.

Aliases, stars, and conditions are not valid sort expressions.

### 8.6 `orderBy()`

```php
public function orderBy(
    Sort ...$sorts,
): self;
```

Behavior:

1. require at least one sort;
2. append all supplied sorts in order;
3. return the same query.

Example:

```php
$u->orderBy(
    $u->name->asc(),
    $u->id->desc(),
);
```

The stored sort list is:

```text
Sort(name, ASC)
Sort(id, DESC)
```

A future compiler translates it to:

```sql
ORDER BY name ASC, id DESC
```

The first sort is primary. Later sorts resolve ties.

Repeated calls append:

```php
$u->orderBy($u->name->asc());
$u->orderBy($u->id->desc());
```

There is no separate query-level descending method. Direction belongs to each
`Sort` entry.

### 8.7 Named-expression ordering

```php
$u
    ->select(
        $u->name->upper()->as('title'),
    )
    ->orderBy(
        $u->get('title')->asc(),
        $u->id->desc(),
    );
```

The sort contains the underlying operation expression.

### 8.8 Sortable expressions

Phase 4 structurally permits any value expression, including:

- fields;
- literals;
- value operations;
- aggregates;
- scalar subqueries.

Type validity and backend support are deferred.

`AliasedExpression`, `StarExpression`, and conditions are excluded by type.

---

## 9. Pagination

### 9.1 Storage

Add:

```php
private ?int $limit = null;

private ?int $offset = null;
```

### 9.2 `limit()`

```php
public function limit(
    ?int $limit,
): self;
```

Behavior:

- non-negative integer sets the limit;
- `null` clears the limit;
- negative value throws `InvalidArgumentException`;
- last call wins;
- return the same query.

These are valid:

```php
$u->limit(0);
$u->limit(25);
$u->limit(null);
```

### 9.3 `offset()`

```php
public function offset(
    ?int $offset,
): self;
```

Behavior mirrors `limit()`:

- non-negative integer sets the offset;
- `null` clears the offset;
- negative value throws;
- last call wins;
- return the same query.

### 9.4 Offset without limit

The database-independent model permits:

```php
$u->offset(50);
```

without a limit.

Whether a target backend can compile that directly is a future adapter concern.

### 9.5 No page helper

Do not add:

```php
$u->page(3, 25);
$u->paginate(...);
```

Page numbering introduces policy decisions:

- zero-based versus one-based pages;
- overflow behavior;
- default page size;
- maximum page size.

Those belong above the core query model or may be added later when required.

---

## 10. Expression normalization in `SelectQuery`

Phase 2 already normalizes a direct `SelectQuery` selection into
`SubqueryExpression`.

Phase 4 needs the same behavior for grouping and ordering.

Keep this logic owner-local and small.

Recommended private method:

```php
private function normalizeValueExpression(
    ValueExpressionInterface|SelectQuery $expression,
): ValueExpressionInterface {
    if ($expression instanceof SelectQuery) {
        return new SubqueryExpression($expression);
    }

    return $expression;
}
```

Use it from:

- direct subquery selection;
- `groupBy()`;
- `x()->asc()`;
- `x()->desc()`.

Do not introduce a public normalizer or normalization service.

The expression factory continues owning normalization for its own operands.

---

## 11. Mutation and accumulation rules

The complete Phase 4 behavior is:

| Method | Behavior |
|---|---|
| `select()` | appends atomically |
| `where()` | appends, implicit top-level AND |
| `groupBy()` | appends |
| `having()` | appends, implicit top-level AND |
| `orderBy()` | appends ordered `Sort` entries |
| `limit()` | last call wins |
| `offset()` | last call wins |

All mutating methods return the same `SelectQuery`.

No immutable snapshot is added.

---

## 12. Local argument validation

Reject immediately:

```php
$u->groupBy();
$u->having();
$u->limit(-1);
$u->offset(-1);
```

Constructor signatures and PHP types reject invalid sort/group/having node
kinds.

`Sort` does not need whole-query validation.

Do not add a recursive query validation pass.

---

## 13. Deferred semantic validation

Phase 4 establishes enough query shape that several semantic rules become
visible, but they still require the complete query and often backend context.

Defer:

- every non-aggregate selection being present in the group list;
- grouping by aggregate expressions;
- aggregate use inside `WHERE`;
- aggregate and non-aggregate operation mixing;
- scalar subquery projection count;
- `IN` subquery projection count;
- valid correlated-query scopes;
- operation argument types;
- sort-expression type support;
- backend limit/offset restrictions;
- alias case-folding and identifier limits.

These checks belong to the future planning boundary.

They should happen automatically when a query is planned or compiled.

Do not add a user-required:

```php
$query->validate();
```

---

## 14. Selection provenance remains deferred

Phase 4 still does not add internal selections.

Therefore it must not add explicit/implicit provenance to `FieldRef` or
selection entries.

The future planner must introduce selection-entry provenance when it first adds
fields internally for:

- joins;
- relation keys;
- composite primary keys;
- identity;
- result assembly.

That information belongs to the selected entry, not the cached `FieldRef`.

---

## 15. Database independence

The new model contains:

- semantic sort directions;
- ordered group expressions;
- semantic conditions;
- integer limit and offset values.

It does not contain:

- `ORDER BY` SQL fragments;
- `GROUP BY` SQL fragments;
- `HAVING` SQL fragments;
- quoted aliases;
- backend null-ordering syntax;
- backend pagination syntax;
- parameter placeholders;
- compiler state.

A future backend adapter decides how to express each concept.

---

## 16. Proposed production files

Add:

```text
src/Query/Sort/
    Sort.php
    SortDirection.php
```

Modify:

```text
src/Query/SelectQuery.php
```

Add documentation:

```text
docs/3-query/phase-4-ordering-grouping-pagination.md
```

Update only as needed:

```text
README.md
docs/3-query/phase-3-semantic-value-operations.md
```

Do not add:

```text
src/Query/Planning/
src/Query/Compiler/
src/Query/Validation/
src/Query/Pagination/
src/Query/Grouping/
```

A dedicated directory is not needed for grouping or pagination because they
are stored directly on `SelectQuery`.

---

## 17. Required examples

### 17.1 Basic ordering

```php
$u->orderBy(
    $u->lastName->asc(),
    $u->firstName->asc(),
    $u->createdAt->desc(),
);
```

### 17.2 Ordering by a named expression

```php
$u
    ->select(
        $u->name->upper()->as('title'),
    )
    ->orderBy(
        $u->get('title')->asc(),
        $u->id->desc(),
    );
```

### 17.3 Grouping and HAVING

```php
$u
    ->select(
        $u->status,
        $u->id->count()->as('total'),
    )
    ->groupBy(
        $u->status,
    )
    ->having(
        x()->gt(
            $u->get('total'),
            10,
        ),
    );
```

### 17.4 Multiple groups and HAVING conditions

```php
$u
    ->groupBy(
        $u->country,
        $u->city,
    )
    ->having(
        x()->gt($u->id->count(), 5),
        x()->lt($u->amount->sum(), 1000),
    );
```

### 17.5 Global aggregate HAVING

```php
$u
    ->select(
        $u->amount->sum()->as('total'),
    )
    ->having(
        x()->gt(
            $u->get('total'),
            100,
        ),
    );
```

### 17.6 Pagination

```php
$u
    ->orderBy(
        $u->id->asc(),
    )
    ->limit(25)
    ->offset(50);
```

### 17.7 Clearing pagination

```php
$u
    ->limit(25)
    ->offset(50)
    ->limit(null)
    ->offset(null);
```

### 17.8 Grouping or ordering by a scalar subquery

```php
$postCount = query($posts, fn ($p) => $p
    ->select($p->id->count())
    ->where(
        x()->eq($p->userId, $u->id),
    )
);

$u
    ->groupBy($postCount)
    ->orderBy(
        x()->desc($postCount),
    );
```

The query is normalized into separate `SubqueryExpression` wrappers that retain
the same mutable nested query.

---

## 18. Tests

### 18.1 Grouping

Test that:

- `groupBy()` requires at least one expression;
- it appends expressions in order;
- repeated calls append;
- it returns the same query;
- direct `SelectQuery` inputs become `SubqueryExpression`;
- value-expression identity is preserved;
- named expressions can be grouped;
- aliases, stars, conditions, and raw literals are excluded by the public API.

### 18.2 HAVING

Test that:

- `having()` requires at least one condition;
- it appends conditions in order;
- repeated calls append;
- it returns the same query;
- root semantics are documented as implicit `AND`;
- nested logical conditions remain intact;
- aggregate named expressions can be reused;
- `having()` works without `groupBy()`.

### 18.3 Sort node

Test that:

- sort retains the exact expression;
- sort retains its direction;
- enum values are `asc` and `desc`;
- direct constructor state is inspectable through getters;
- fluent `asc()` and `desc()` return `Sort`;
- factory `asc()` and `desc()` return equivalent nodes;
- direction wrappers do not mutate the original expression.

### 18.4 Ordering

Test that:

- `$expression->asc()` creates ascending `Sort`;
- `$expression->desc()` creates descending `Sort`;
- fluent direction methods delegate to `x()`;
- `x()->asc()` and `x()->desc()` preserve expression identity;
- a direct query input becomes `SubqueryExpression`;
- `orderBy()` requires at least one sort;
- one call accepts multiple sorts;
- repeated calls append;
- supplied list order preserves precedence;
- `orderBy()` returns the same query;
- named expressions can be sorted;
- aggregate and operation expressions can be sorted;
- aliases, stars, and conditions are not valid sort expressions.

### 18.5 Pagination

Test that:

- default limit and offset are null;
- zero is accepted;
- positive values are accepted;
- negative values throw;
- null clears the value;
- repeated calls use last-call-wins;
- offset without limit is retained;
- both methods return the same query.

### 18.6 Interaction

Test a complete query containing:

- selections;
- `WHERE`;
- grouping;
- `HAVING`;
- multiple sorts;
- limit;
- offset.

Verify all getter lists preserve their independent ordering.

### 18.7 Regression and architecture

All Phase 1–3 tests must continue passing.

The implementation must not introduce:

- SQL strings;
- database dependencies;
- planning;
- execution;
- a validator;
- immutable snapshots;
- page-number policy;
- null-ordering abstractions;
- selection provenance.

---

## 19. Documentation

Add:

```text
docs/3-query/phase-4-ordering-grouping-pagination.md
```

Document:

- `groupBy()`;
- implicit top-level `AND` in `having()`;
- grouping and ordering through `get()`;
- sort precedence;
- explicit `asc()` and `desc()` wrappers;
- universal `x()->asc()` and `x()->desc()` forms;
- multiple directions in one `orderBy()` call;
- limit and offset;
- clearing pagination with `null`;
- offset without limit;
- deferred aggregate/group semantic validation;
- absence of SQL and execution.

Update the README current-scope section only after implementation is complete.

---

## 20. Carried-forward TODO list

The following previously discussed items remain deferred.

### 20.1 Selection provenance

When a future planner adds selections automatically, introduce a selection
entry with explicit/implicit provenance.

Do not put that state on `FieldRef`.

### 20.2 Relation requirements

Future joins and internally required fields must come from class-based relation
metadata.

### 20.3 Composite primary keys

Future joins, relation predicates, internal selections, key filters, and result
assembly must include every key component.

### 20.4 Planning and semantic validation

The next architectural area should resolve:

- valid field-reference scopes;
- scalar and `IN` subquery shape;
- aggregate/group compatibility;
- operation type compatibility;
- relation and source paths;
- backend-independent planned selections.

### 20.5 Backend compilation

SQL syntax differences remain delegated to Cycle Database or Doctrine DBAL
adapters.

Do not encode dialect decisions in this query model.

---

## 21. Quality gates

Run:

```text
composer validate --strict
composer dump-autoload
composer test
composer analyse
composer check-style
composer check
```

Report:

- starting SHA;
- changed production files;
- changed test files;
- documentation changes;
- quality-command results;
- final commit SHA, if committed.

---

## 22. Stop condition

Phase 4 is complete when the query model supports:

- ordered grouping expressions;
- ordered `HAVING` conditions;
- expression-owned ascending and descending sort wrappers;
- universal `x()` sort-direction construction;
- multiple ordered sort entries in one `orderBy()` call;
- sort precedence;
- limit;
- offset;
- query-local named-expression reuse in grouping, `HAVING`, and ordering.

Stop after that.

Do not begin:

- planning;
- semantic validation;
- SQL compilation;
- execution;
- joins;
- relations;
- selection provenance;
- cursor pagination;
- null-ordering options.
