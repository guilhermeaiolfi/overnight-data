# Phase 3 — Semantic Value Operations and Named Expressions

## Status

Implemented in the query model.

Phase 3 adds semantic value operations and query-local named expression lookup
without introducing SQL compilation, planning, execution, relations, or later
query phases.

Implementation baseline:

```text
Repository: guilhermeaiolfi/overnight-data
Starting SHA: 0cce935d7b50f65626501e06e96dab4b40d9ad09
```

Keep the Phase 3 commit limited to query code, query tests, query documentation,
and the corresponding README update.

---

## 1. Purpose

Phase 1 established the basic mutable `SelectQuery` model.

Phase 2 added:

- query-owned star expressions;
- `COUNT`, `COUNT DISTINCT`, and `SUM`;
- scalar and correlated subqueries;
- `EXISTS`;
- literal and subquery `IN`.

Phase 3 adds two capabilities already discussed for the public query API:

1. semantic value operations such as `UPPER`, `LOWER`, `CONCAT`, `COALESCE`,
   and `ADD`;
2. query-local lookup of selected aliased expressions through
   `$query->get($alias)`.

The phase remains database-independent.

It represents expression meaning but does not translate that meaning into SQL.

---

## 2. Guiding rule

Use the smallest object that represents the operation required now.

Preserve the settled public API without introducing speculative function,
planning, or compiler infrastructure.

In particular:

- use one semantic value-operation node;
- use an enum for the explicitly supported operations;
- keep `x()` as the universal operation API;
- provide fluent shorthand only where one expression is the natural receiver;
- keep the named-expression registry as one plain array owned by
  `SelectQuery`;
- return the registered underlying expression directly;
- do not introduce alias-reference AST nodes;
- do not introduce a generic function registry;
- do not expose arbitrary database function names.

---

## 3. Carried-forward public API rules

### 3.1 `x()` is the universal operation API

Every operation introduced in this phase is available from `x()`:

```php
x()->upper($u->name);
x()->lower($u->name);

x()->concat(
    $u->firstName,
    ' ',
    $u->lastName,
);

x()->coalesce(
    $u->preferredName,
    $u->name,
    'Unknown',
);

x()->add(
    $u->subtotal,
    $u->tax,
);
```

### 3.2 Unary operations may have fluent shorthand

When there is one natural receiver, the value expression exposes a shorthand:

```php
$u->name->upper();
$u->name->lower();
```

These are equivalent to:

```php
x()->upper($u->name);
x()->lower($u->name);
```

The shorthand delegates to the same stateless `ExpressionFactory`.

There must not be separate operation-building implementations.

### 3.3 Multi-argument operations stay on `x()`

Do not add:

```php
$u->firstName->concat($u->lastName);
$u->preferredName->coalesce($u->name);
$u->subtotal->add($u->tax);
```

Those operations have multiple peer arguments and no privileged receiver.

Use:

```php
x()->concat(...);
x()->coalesce(...);
x()->add(...);
```

### 3.4 Aggregation remains unary

The existing aggregate API remains unchanged:

```php
$u->amount->sum();
x()->sum($u->amount);
```

`SUM` still accepts one expression.

To aggregate a per-row combination, compose the value expression first:

```php
x()->add(
    $u->subtotal,
    $u->tax,
)->sum();
```

Equivalent universal form:

```php
x()->sum(
    x()->add($u->subtotal, $u->tax),
);
```

### 3.5 Query star remains special

`StarExpression` is not an ordinary value expression.

It is valid only where already supported:

```php
$u->star()->count();
x()->count($u->star());
```

It cannot be passed to the value operations in this phase.

---

## 4. Goals

Phase 3 must support:

```php
$u = query($users);

$u->select(
    $u->id,
    $u->name->upper()->as('title'),
    x()->concat(
        $u->firstName,
        ' ',
        $u->lastName,
    )->as('full_name'),
    x()->coalesce(
        $u->preferredName,
        $u->name,
        'Unknown',
    )->as('display_name'),
    x()->add(
        $u->subtotal,
        $u->tax,
    )->sum()->as('total'),
);
```

It must also support reuse of a selected expression by alias:

```php
$u
    ->select(
        $u->name->upper()->as('title'),
    )
    ->where(
        x()->eq(
            $u->get('title'),
            'ADMIN',
        ),
    );
```

`get('title')` returns the exact underlying `UPPER(name)` expression.

It does not produce a SQL alias reference.

---

## 5. Non-goals

Do not implement:

- arbitrary function names;
- a function registry;
- custom operation plugins;
- database-specific function classes;
- SQL compilation;
- query execution;
- query planning;
- whole-query validation;
- field-type validation;
- return-type inference;
- representation conversion;
- ordering;
- grouping;
- `HAVING`;
- pagination;
- joins;
- relation traversal;
- result hydration;
- `SUBTRACT`;
- `MULTIPLY`;
- `DIVIDE`;
- `MODULO`;
- `TRIM`;
- date functions;
- JSON functions;
- aliases as database-visible references;
- alias replacement or removal;
- automatic expression deduplication;
- selection provenance.

Those concerns require separate phases.

---

## 6. Semantic value-operation model

### 6.1 Operation enum

Add:

```php
enum ValueOperation: string
{
    case UPPER = 'upper';
    case LOWER = 'lower';
    case CONCAT = 'concat';
    case COALESCE = 'coalesce';
    case ADD = 'add';
}
```

These are semantic operations.

The enum values are not SQL function names and must not be emitted directly as
SQL without a future backend adapter deciding how to translate them.

### 6.2 Operation expression

Add:

```php
final class ValueOperationExpression
    extends AbstractAggregateableExpression
{
    /**
     * @param non-empty-list<ValueExpressionInterface> $arguments
     */
    public function __construct(
        ValueOperation $operation,
        array $arguments,
    );

    public function getOperation(): ValueOperation;

    /**
     * @return non-empty-list<ValueExpressionInterface>
     */
    public function getArguments(): array;
}
```

The constructor:

- normalizes the argument array to a zero-based list;
- rejects an empty list;
- enforces the operation arity;
- stores the supplied expression objects without copying them.

### 6.3 Arity

Required argument counts:

| Operation | Arguments |
|---|---:|
| `UPPER` | exactly 1 |
| `LOWER` | exactly 1 |
| `CONCAT` | at least 2 |
| `COALESCE` | at least 2 |
| `ADD` | at least 2 |

A one-argument `CONCAT`, `COALESCE`, or `ADD` is rejected.

Do not silently return the argument.

### 6.4 Why the node is aggregateable

The operation result is a value expression and can be used as an aggregate
input:

```php
x()->add($u->subtotal, $u->tax)->sum();
x()->concat($u->countryCode, $u->number)->countDistinct();
```

`ValueOperationExpression` therefore extends the existing
`AbstractAggregateableExpression`.

It also inherits `as()`, `upper()`, and `lower()` through the value-expression
base classes.

---

## 7. Operation semantics

The AST represents these meanings independently of a database dialect.

### 7.1 `UPPER`

```php
x()->upper($expression);
$expression->upper();
```

One value argument.

The intended result is the uppercase textual value.

A null input produces a null result.

### 7.2 `LOWER`

```php
x()->lower($expression);
$expression->lower();
```

One value argument.

The intended result is the lowercase textual value.

A null input produces a null result.

### 7.3 `CONCAT`

```php
x()->concat($first, $separator, $last);
```

Two or more ordered arguments.

The intended result is their textual concatenation.

`CONCAT` is null-propagating: if an argument evaluates to null, the result is
null.

A future adapter must preserve that semantic even when the target database's
native `CONCAT` function has different null behavior.

### 7.4 `COALESCE`

```php
x()->coalesce($preferred, $fallback, 'Unknown');
```

Two or more ordered arguments.

The intended result is the first non-null value.

### 7.5 `ADD`

```php
x()->add($subtotal, $tax);
x()->add($a, $b, $c);
```

Two or more ordered arguments.

The intended result is arithmetic addition.

`ADD` is null-propagating.

The argument order is preserved in the AST.

Phase 3 does not verify that arguments are numeric.

---

## 8. Fluent unary operations

### 8.1 `AbstractValueExpression`

Add to the existing base:

```php
final public function upper(): ValueOperationExpression;

final public function lower(): ValueOperationExpression;
```

Each method delegates to `x()`:

```php
final public function upper(): ValueOperationExpression
{
    return x()->upper($this);
}
```

Put the methods on `AbstractValueExpression`, not only
`AbstractAggregateableExpression`.

This permits unary value transformations on:

- fields;
- literals;
- aggregates;
- subqueries;
- value-operation expressions.

Examples:

```php
$u->name->upper();

$u->amount
    ->sum()
    ->as('total'); // Existing aggregate behavior.

x()->coalesce(
    $u->amount->sum(),
    0,
)->as('total');

x()->upper(
    $u->name->countDistinct(),
);
```

The final example is structurally representable. Field-type and result-type
validity remains deferred.

### 8.2 No fluent multi-argument methods

Do not add `concat()`, `coalesce()`, or `add()` to
`AbstractValueExpression`.

---

## 9. `ExpressionFactory` operation API

Add:

```php
public function upper(
    mixed $expression,
): ValueOperationExpression;

public function lower(
    mixed $expression,
): ValueOperationExpression;

public function concat(
    mixed ...$arguments,
): ValueOperationExpression;

public function coalesce(
    mixed ...$arguments,
): ValueOperationExpression;

public function add(
    mixed ...$arguments,
): ValueOperationExpression;
```

### 9.1 Argument normalization

Each operation argument is normalized as follows:

1. `AliasedExpression` is rejected;
2. `StarExpression` is rejected;
3. `ConditionInterface` is rejected;
4. `SelectQuery` becomes `SubqueryExpression`;
5. `ValueExpressionInterface` is preserved;
6. every other PHP value becomes `LiteralExpression`.

This permits:

```php
x()->concat($u->firstName, ' ', $u->lastName);
x()->coalesce($u->name, null, 'Unknown');
x()->add($u->subtotal, 10);
```

It also permits value objects such as `DateTimeImmutable` to remain explicit
literal values when a future operation accepts them.

Do not add a public normalizer service.

### 9.2 Shared operand hardening

The existing private operand normalization is also used by comparisons and
`IN` list normalization.

Update the shared normalizer so known query nodes that are not value
expressions cannot accidentally become `LiteralExpression` values.

These must be rejected:

```php
x()->eq($u->id, $u->star());
x()->eq($u->id, x()->eq($u->active, true));

x()->concat($u->name, $u->star());
x()->coalesce($u->name, x()->isNull($u->name));
```

Do not reject ordinary PHP objects merely because they are objects.

Definitions, dates, enums, and domain value objects may still be legitimate
literal values until field-type conversion is introduced.

---

## 10. Aggregate nesting through operations

Phase 2 rejects direct nested aggregates:

```php
x()->sum(
    $u->amount->sum(),
);
```

Phase 3 must not reintroduce that invalid shape indirectly:

```php
x()->add(
    $u->amount->sum(),
    1,
)->sum();
```

### 10.1 Valid operation over aggregate values

An operation may contain aggregate expressions:

```php
x()->add(
    $u->subtotal->sum(),
    $u->tax->sum(),
);
```

This represents:

```text
SUM(subtotal) + SUM(tax)
```

and is valid as an operation result.

### 10.2 Invalid aggregate over an aggregate-containing operation

This is rejected:

```php
x()->add(
    $u->subtotal->sum(),
    $u->tax,
)->sum();
```

because it attempts to aggregate an expression that already contains an
aggregate at the same query level.

### 10.3 Factory guard

Extend the existing aggregate-input guard to recursively inspect
`ValueOperationExpression` arguments.

The recursion:

- rejects `AggregateExpression`;
- descends into nested `ValueOperationExpression` arguments;
- does not descend into `SubqueryExpression`, because a subquery is a separate
  query level.

This remains a local expression-tree invariant.

It is not a whole-query validator.

---

## 11. Query-local named expressions

### 11.1 Purpose

A selected alias should be reusable later in the same query without storing an
external PHP variable:

```php
$u
    ->select(
        $u->name->upper()->as('title'),
    )
    ->where(
        x()->eq(
            $u->get('title'),
            'ADMIN',
        ),
    );
```

The lookup reuses the semantic expression.

It must not assume the database allows a `SELECT` alias inside `WHERE`.

### 11.2 Owner-local registry

Add to `SelectQuery`:

```php
/**
 * @var array<string, ValueExpressionInterface>
 */
private array $namedExpressions = [];
```

The map is owned by that query only.

Do not add:

- a global expression registry;
- a Registry-level cache;
- an expression-manager service;
- a separate identity object.

### 11.3 Public lookup

Add:

```php
public function get(string $name): ValueExpressionInterface;
```

`get()` is the settled query DSL lookup API.

It is a keyed lookup, not a conventional object-property getter.

Behavior:

1. trim surrounding whitespace;
2. reject an empty name with `InvalidArgumentException`;
3. return the exact registered expression object;
4. throw `UnknownQueryExpressionException` when absent.

Required identity:

```php
$expression = $u->name->upper();

$u->select(
    $expression->as('title'),
);

$u->get('title') === $expression;
```

Repeated lookup returns the same object.

### 11.4 Registration

An expression is registered only when an `AliasedExpression` is successfully
passed to `SelectQuery::select()`.

Creating an alias wrapper alone does not register it:

```php
$title = $u->name->upper()->as('title');

$u->get('title'); // Unknown until $title is selected.
```

After:

```php
$u->select($title);
```

the lookup succeeds.

### 11.5 Underlying expression

Register:

```php
$aliased->getExpression()
```

not the `AliasedExpression` wrapper.

Therefore:

```php
$u->get('title')
```

can be used directly in:

- comparisons;
- null conditions;
- value operations;
- aggregates when that expression is aggregateable;
- later selections.

No `NamedExpressionRef` or `AliasRef` node is introduced.

### 11.6 What is not registered

Do not automatically register:

- unaliased fields;
- definition-level field aliases;
- direct subqueries without query aliases;
- expression object identities;
- generated names.

Only query selection aliases participate.

### 11.7 Query ownership

Named expressions are visible only through the query where their alias was
selected.

An alias selected in an inner query is not visible in the outer query.

An alias selected in the outer query is not automatically visible through the
inner query.

The underlying expression may itself contain correlated field references, but
the map remains owner-local.

---

## 12. Duplicate aliases

A query cannot contain two selected expressions with the same exact alias.

Reject duplicates:

```php
$u->select(
    $u->name->as('title'),
    $u->email->as('title'),
);
```

Also reject a duplicate added later:

```php
$u->select($u->name->as('title'));
$u->select($u->email->as('title'));
```

The model compares normalized aliases exactly and case-sensitively.

Therefore these remain distinct in Phase 3:

```php
$u->name->as('title');
$u->name->as('Title');
```

Backend-specific case-folding constraints belong to a later adapter.

### 12.1 Same expression, different aliases

This is valid:

```php
$expression = $u->name->upper();

$u->select(
    $expression->as('name_upper'),
    $expression->as('title'),
);
```

Both aliases resolve to the same underlying expression object:

```php
$u->get('name_upper') === $expression;
$u->get('title') === $expression;
```

### 12.2 Same field selected several ways

This remains valid:

```php
$u->select(
    $u->name,
    $u->name->as('display_name'),
    $u->name->upper()->as('title'),
);
```

A field or expression does not own one exclusive alias.

Aliases belong to selection wrappers.

---

## 13. Atomic `select()` behavior

Alias validation and registration must not leave the query partially mutated.

For one `select()` call:

1. normalize all direct nested queries into `SubqueryExpression`;
2. collect all aliases in the incoming batch;
3. reject aliases already registered;
4. reject duplicate aliases within the incoming batch;
5. only after all checks pass:
   - append every selection;
   - register every named expression.

Example:

```php
$before = $u->getSelections();

try {
    $u->select(
        $u->id,
        $u->name->as('title'),
        $u->email->as('title'),
    );
} catch (InvalidArgumentException) {
}
```

After failure:

- `$u->getSelections()` equals `$before`;
- `title` is not registered.

Do not append the valid leading selections before discovering a later duplicate.

---

## 14. Unknown-expression exception

Add:

```php
final class UnknownQueryExpressionException
    extends InvalidArgumentException
{
    public static function forQuery(
        string $name,
        string $sourceName,
    ): self;
}
```

The message identifies:

- the requested expression name;
- the query source definition name.

Do not add a large named-expression exception hierarchy.

Duplicate aliases may use `InvalidArgumentException`.

---

## 15. Database independence

Phase 3 operation nodes contain:

- a semantic operation enum;
- ordered value-expression arguments.

They do not contain:

- SQL function names;
- SQL operators;
- quoted identifiers;
- parameter placeholders;
- backend capability flags;
- SQL aliases;
- result-column aliases used as operands.

A future compiler may translate:

```text
UPPER
LOWER
CONCAT
COALESCE
ADD
```

differently for each database, but it must preserve the semantics defined by
this model.

---

## 16. Type and representation handling

Phase 3 does not decide whether an operation is valid for a field type.

It can structurally represent:

```php
$u->integerField->upper();
x()->add($u->name, $u->email);
```

Those may be semantically invalid.

Type-aware validation belongs to a future planning or compilation boundary,
where the operation, field definitions, representations, and backend
capabilities are all available.

Literal values remain unconverted PHP values.

`FieldType` and representations remain the conversion authority when binding
or result conversion is introduced.

---

## 17. Proposed production files

Add:

```text
src/Query/Expression/
    ValueOperation.php
    ValueOperationExpression.php

src/Query/Exception/
    UnknownQueryExpressionException.php
```

Modify:

```text
src/Query/SelectQuery.php
src/Query/ExpressionFactory.php
src/Query/Expression/AbstractValueExpression.php
```

The aggregate-input guard in `ExpressionFactory` must also understand
`ValueOperationExpression`.

Add documentation:

```text
docs/3-query/phase-3-semantic-value-operations.md
```

Update:

```text
README.md
docs/3-query/phase-2-aggregate-subqueries.md
```

only where needed to link the new phase or describe the now-implemented
composition example.

Do not add:

```text
src/Query/Function/
src/Query/Planning/
src/Query/Compiler/
src/Query/Registry/
```

---

## 18. Public API summary

### `AbstractValueExpression`

Add:

```php
public function upper(): ValueOperationExpression;

public function lower(): ValueOperationExpression;
```

### `ExpressionFactory`

Add:

```php
public function upper(
    mixed $expression,
): ValueOperationExpression;

public function lower(
    mixed $expression,
): ValueOperationExpression;

public function concat(
    mixed ...$arguments,
): ValueOperationExpression;

public function coalesce(
    mixed ...$arguments,
): ValueOperationExpression;

public function add(
    mixed ...$arguments,
): ValueOperationExpression;
```

### `SelectQuery`

Add:

```php
public function get(
    string $name,
): ValueExpressionInterface;
```

`select()` keeps its current public signature but gains atomic alias
registration and duplicate-alias rejection.

---

## 19. Required examples

### 19.1 Upper and lower

```php
$u->select(
    $u->name->upper()->as('name_upper'),
    x()->lower($u->email)->as('email_lower'),
);
```

### 19.2 Concatenation

```php
$u->select(
    x()->concat(
        $u->firstName,
        ' ',
        $u->lastName,
    )->as('full_name'),
);
```

### 19.3 Coalesce

```php
$u->select(
    x()->coalesce(
        $u->preferredName,
        $u->name,
        'Unknown',
    )->as('display_name'),
);
```

### 19.4 Arithmetic composition before aggregation

```php
$u->select(
    x()->add(
        $u->subtotal,
        $u->tax,
    )->sum()->as('total'),
);
```

### 19.5 Aggregate combination

```php
$u->select(
    x()->add(
        $u->subtotal->sum(),
        $u->tax->sum(),
    )->as('total'),
);
```

### 19.6 Named expression reuse

```php
$u
    ->select(
        $u->name->upper()->as('title'),
    )
    ->where(
        x()->eq(
            $u->get('title'),
            'ADMIN',
        ),
    );
```

The condition contains the same `ValueOperationExpression` selected under
`title`.

It does not contain a string alias reference.

### 19.7 Multiple aliases

```php
$normalizedName = $u->name->upper();

$u->select(
    $normalizedName->as('name_upper'),
    $normalizedName->as('title'),
);
```

---

## 20. Tests

### 20.1 Operation enum and node

Test that:

- every supported operation has the expected enum case;
- operation arguments preserve order;
- argument arrays are normalized to lists;
- `UPPER` and `LOWER` require exactly one argument;
- `CONCAT`, `COALESCE`, and `ADD` require at least two;
- invalid direct-constructor arity throws;
- no arbitrary string operation is accepted.

### 20.2 Factory normalization

Test that:

- PHP values become `LiteralExpression`;
- `SelectQuery` becomes `SubqueryExpression`;
- existing value expressions are preserved;
- aliases are rejected;
- stars are rejected;
- conditions are rejected;
- ordinary PHP value objects remain literal values.

### 20.3 Fluent equivalence

Test that:

```php
$field->upper();
x()->upper($field);
```

create equivalent operation nodes.

Do the same for `lower()`.

Verify fluent methods delegate to factory semantics.

### 20.4 Multi-argument operations

Test:

- ordered `concat` arguments;
- ordered `coalesce` arguments;
- ordered `add` arguments;
- literals mixed with fields;
- nested operation expressions;
- subquery operands;
- aliases, stars, and conditions rejected as arguments.

### 20.5 Aggregate composition

Test that:

- `x()->add($a, $b)->sum()` works;
- `x()->sum(x()->add($a, $b))` works;
- `x()->add($a->sum(), $b->sum())` works;
- `x()->add($a->sum(), $b)->sum()` throws;
- nested operation wrappers cannot bypass aggregate nesting guards;
- aggregate checks do not descend into `SubqueryExpression`.

### 20.6 Named expression registration

Test that:

- selecting an alias registers its underlying expression;
- `get()` returns the exact expression object;
- repeated `get()` calls return the same object;
- surrounding whitespace in lookup names is trimmed;
- empty lookup names throw;
- unknown names throw `UnknownQueryExpressionException`;
- creating but not selecting an alias does not register it;
- unaliased selections are not registered;
- definition-level field aliases are not registered.

### 20.7 Duplicate aliases

Test that:

- duplicate aliases in one `select()` call throw;
- duplicate aliases across calls throw;
- the same expression may have different aliases;
- different expressions may have different aliases;
- alias comparison is exact and case-sensitive.

### 20.8 Atomic selection

Test that a failed `select()` call:

- appends no incoming selection;
- registers no incoming alias;
- preserves previously selected expressions;
- preserves previously registered aliases.

### 20.9 Query ownership

Test that:

- named expressions are query-local;
- an inner-query alias is not visible through the outer query;
- an outer-query alias is not registered in the inner query;
- aliases may wrap correlated expressions without changing field ownership.

### 20.10 Regression and architecture

All Phase 1 and Phase 2 tests must continue passing.

The implementation must not introduce:

- SQL strings;
- database dependencies;
- planning;
- execution;
- a validator;
- a global expression registry;
- arbitrary function names;
- alias-reference nodes.

---

## 21. Documentation

Add:

```text
docs/3-query/phase-3-semantic-value-operations.md
```

Document:

- semantic operations;
- fluent unary shorthand;
- universal `x()` methods;
- why multi-argument operations stay on `x()`;
- composing `ADD` before `SUM`;
- combining aggregate results with `ADD`;
- named-expression lookup;
- the fact that `get()` returns the underlying expression;
- duplicate alias behavior;
- query-local ownership;
- deferred type validation;
- absence of SQL and execution.

Update the README current-scope section only after implementation is complete.

---

## 22. Carried-forward TODO list

The following previously discussed items remain deferred.

### 22.1 Selection provenance

A later planning/result phase must distinguish:

- selections explicitly requested by the caller;
- selections internally added for joins, relations, keys, or result assembly.

Do not add this flag in Phase 3 because no planner adds internal selections yet.

### 22.2 Relation requirements

Future join predicates and internally required fields must come from the
class-based relation metadata.

Do not infer relation behavior from names.

### 22.3 Composite primary keys

Future relation joins, key filters, internal selections, and result assembly
must include every key component.

Do not introduce single-key assumptions.

### 22.4 More semantic operations

Possible later operations include:

```text
SUBTRACT
MULTIPLY
DIVIDE
TRIM
DATE PARTS
JSON OPERATIONS
```

Add them only when a concrete query use case requires them.

### 22.5 Custom operation extensibility

Do not design a custom-operation plugin system yet.

When real custom-operation pressure appears, preserve:

- semantic operation classes;
- database-independent meaning;
- backend-specific translation outside the query model.

### 22.6 Planning and semantic validation

A later planning boundary must eventually check:

- scalar subquery projection count;
- `IN` subquery projection count;
- valid correlated-query scopes;
- aggregate/grouping rules;
- operation argument types;
- backend capabilities.

Those checks should happen automatically when planning or compilation consumes
the query.

Do not add a user-required validation call.

---

## 23. Quality gates

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

## 24. Stop condition

Phase 3 is complete when the query model supports:

- semantic `UPPER`;
- semantic `LOWER`;
- semantic `CONCAT`;
- semantic `COALESCE`;
- semantic `ADD`;
- fluent unary operation shorthand;
- multi-argument universal operation construction;
- composition before aggregation;
- owner-local named-expression lookup;
- duplicate-alias rejection;
- atomic alias registration.

Stop after that.

Do not begin:

- ordering;
- grouping;
- `HAVING`;
- pagination;
- planning;
- SQL compilation;
- execution;
- relation traversal;
- selection provenance;
- arbitrary custom operations.
