# Phase 5 — Bound Query Execution and Overnight Cycle Backend

## Status

Architecture specification for review only.

This phase spans two repositories:

```text
overnight-data
    neutral executable-query contract and bound SelectQuery behavior

overnight
    integration with the existing database layer
    internal Cycle-backed translation and execution
```

Do not begin implementation until this specification is approved.

Do not begin relation joins, eager loading, writes, ORM hydration, or later
query phases automatically after implementing this phase.

Implementation baselines:

```text
overnight-data:
    use the current Phase 4 branch HEAD
    it must be a descendant of:
    6fb6ee8426d2c2a5bd7bde48dfff21b242838a02

overnight:
    4e7c64194786d0a2472b241832eaba78d1f358e2
```

The exact `overnight-data` starting SHA must be reported before implementation
because the Phase 4 documentation was committed after the code SHA above.

---

## 1. Purpose

Phases 1–4 created a database-independent relation-free `SELECT` query model.

Phase 5 makes that model executable while preserving these boundaries:

- application code uses only `ON\Data` query objects and neutral Overnight
  database services;
- Cycle classes never appear in application-facing query APIs;
- `overnight-data` does not depend on the Overnight framework;
- `overnight-data` does not depend on Cycle;
- Overnight reuses its existing named database configuration and cached
  database instances;
- Cycle remains responsible for final SQL generation, dialect handling,
  prepared parameters, and database execution;
- no second database connection system is introduced;
- future relation handlers and loaders will manipulate the same `SelectQuery`
  API rather than a second statement-builder API;
- this phase introduces neither an execution-plan object nor a second physical
  query model.

The intended developer experience is:

```php
use ON\DB\Query\QueryDatabase;

final class UserRepository
{
    public function __construct(
        private QueryDatabase $database,
    ) {
    }

    public function getActiveUsers(): array
    {
        $u = $this->database->query($this->users);

        return $u
            ->select(
                $u->id,
                $u->name,
            )
            ->where(
                x()->eq($u->active, true),
            )
            ->orderBy(
                $u->name->asc(),
            )
            ->fetchAll();
    }
}
```

No Cycle type is imported or returned.

---

## 2. Architectural pipeline

The public pipeline is:

```text
ON\Data DefinitionInterface
        ↓
ON\DB\Query\QueryDatabase::query()
        ↓
bound ON\Data\Query\SelectQuery
        ↓
SelectQuery::fetchAll() / fetchOne() / iterate()
        ↓
ON\Data\Database\QueryExecutorInterface
        ↓
Overnight internal Cycle executor
        ↓
Cycle query builder
        ↓
Cycle driver/compiler
        ↓
database
        ↓
plain PHP rows
```

The internal Cycle query builder and result objects must not cross the adapter
boundary.

---

## 3. Core decisions

### 3.1 `SelectQuery` remains the query model

Do not add:

- an executable-query wrapper;
- a proxy mirroring every fluent query method;
- a second query model;
- `SelectQuerySpec`;
- a public Cycle query object.

`SelectQuery` remains both:

- the mutable query model;
- the fluent query builder.

It gains only an optional neutral execution binding.

### 3.2 Bound and unbound queries

The standalone helper remains unbound:

```php
$u = query($users);

$u->isExecutable(); // false
```

Overnight creates a bound query:

```php
$u = $database->query($users);

$u->isExecutable(); // true
```

Both are the same `ON\Data\Query\SelectQuery` class.

### 3.3 Detachment

A bound query can be detached:

```php
$u->detach();

$u->isExecutable(); // false
```

`detach()` mutates and returns the same query:

```php
$detached = $u->detach();

$detached === $u;
```

It does not clone the query.

This avoids rebuilding cached `FieldRef`, `StarExpression`, subquery, and
expression ownership references.

### 3.4 No `getSelectQuery()`

Do not add:

```php
$query->getSelectQuery();
```

The object already is a `SelectQuery`.

`detach()` is the explicit operation that removes execution behavior while
preserving the model.

### 3.5 Cycle is private implementation detail

No public signature in `overnight-data` or the public Overnight query facade may
contain a class from:

```text
Cycle\
```

Cycle classes are permitted only inside Overnight's backend implementation.


### 3.6 One fluent query API

`SelectQuery` is the only fluent select-query API.

Do not add a second internal API such as:

```text
SelectStatement
PhysicalSelectQuery
PlannedSelect
```

Future relation handlers must create and mutate ordinary `SelectQuery`
instances using the same methods application developers use.

The APIs may gain capabilities required by relation handling, such as joins and
internally required selections, but those capabilities belong to the existing
query model rather than a parallel statement model.

Do not introduce a shared interface merely because public and internal callers
use the same methods. They already use the same concrete model.

### 3.7 No execution-plan object

Phase 5 does not add:

```text
ExecutionPlan
QueryPlan
StatementPlan
```

A later relation loader will own its lifecycle directly:

1. configure the root `SelectQuery`;
2. execute it;
3. obtain parent keys;
4. create another ordinary `SelectQuery` when separate loading is required;
5. execute that query;
6. assemble the relation result;
7. recursively run child loaders.

That loader tree is already the executable structure. Wrapping it in a plan
object would add another concept without removing responsibility from the
loaders.

A formal plan object should be reconsidered only if a concrete feature requires
global scheduling, cross-relation batching, parallel execution, inspection of
all deferred work, or reusable/cached execution strategies.

---

# Part A — `overnight-data`

## 4. Neutral execution contract

Add:

```php
namespace ON\Data\Database;

use ON\Data\Query\SelectQuery;

interface QueryExecutorInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(
        SelectQuery $query,
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function fetchOne(
        SelectQuery $query,
    ): ?array;

    /**
     * @return iterable<array<string, mixed>>
     */
    public function iterate(
        SelectQuery $query,
    ): iterable;
}
```

The interface knows only the `ON\Data` query model and plain PHP results.

It does not expose:

- connections;
- SQL;
- parameters;
- Cycle queries;
- Cycle result cursors;
- PDO statements;
- transaction APIs.

### 4.1 Why the executor is separate

`SelectQuery` initiates execution for developer ergonomics, but it does not
translate or execute itself.

Its execution methods delegate to `QueryExecutorInterface`.

This preserves a replaceable backend boundary without forcing the developer to
write:

```php
$executor->fetchAll($query);
```

as the normal workflow.

---

## 5. `SelectQuery` execution binding

Modify the constructor:

```php
public function __construct(
    DefinitionInterface $source,
    ?QueryExecutorInterface $executor = null,
);
```

The existing `query()` helper continues constructing the query without an
executor.

Add:

```php
public function isExecutable(): bool;

public function detach(): self;

/**
 * @return list<array<string, mixed>>
 */
public function fetchAll(): array;

/**
 * @return array<string, mixed>|null
 */
public function fetchOne(): ?array;

/**
 * @return iterable<array<string, mixed>>
 */
public function iterate(): iterable;
```

### 5.1 Delegation

Each execution method requires a bound executor and delegates the exact query
instance:

```php
public function fetchAll(): array
{
    return $this->requireExecutor()->fetchAll($this);
}
```

Do not expose a public `getExecutor()` method.

### 5.2 Unbound execution

Calling an execution method on an unbound query throws:

```php
QueryNotExecutableException
```

Example:

```php
$u = query($users);

$u->fetchAll();
// QueryNotExecutableException
```

### 5.3 `fetchOne()`

`fetchOne()` returns the first row or `null`.

It must not mutate:

- limit;
- offset;
- ordering;
- selections;
- any other query state.

The executor decides how to efficiently request one row internally.

### 5.4 `iterate()`

`iterate()` returns an iterable of mapped plain PHP rows.

It must not expose a Cycle result object.

Backend exceptions raised during iteration must still be wrapped in the public
execution exception contract.

---

## 6. Core exceptions

Add:

```php
namespace ON\Data\Database\Exception;

final class QueryNotExecutableException extends LogicException
{
}
```

Add:

```php
final class QueryExecutionException extends RuntimeException
{
    public static function forQuery(
        SelectQuery $query,
        Throwable $previous,
    ): self;
}
```

Add:

```php
final class UnsupportedQueryException extends LogicException
{
    public static function forQuery(
        SelectQuery $query,
        string $reason,
    ): self;
}
```

Use:

- `QueryNotExecutableException` when no executor is bound;
- `UnsupportedQueryException` when the query model cannot be executed by the
  current relation-free backend;
- `QueryExecutionException` when translation or database execution fails
  unexpectedly.

The original backend exception may remain available through
`Throwable::getPrevious()`.

Application code must not need to catch Cycle exceptions.

---

## 7. Core tests

Add tests using a fake `QueryExecutorInterface`.

Test that:

- the `query()` helper creates an unbound query;
- direct construction can bind a neutral executor;
- `isExecutable()` reports the binding;
- all execution methods delegate the same query object;
- `fetchAll()` returns executor rows;
- `fetchOne()` returns an executor row or null;
- `iterate()` yields executor rows;
- unbound execution methods throw;
- `detach()` returns the same query;
- `detach()` removes execution;
- detachment does not change query state;
- detachment does not replace cached field references;
- detachment does not replace cached star references;
- no public core API contains Cycle types.

Do not add Cycle to `overnight-data` Composer dependencies.

---

# Part B — Overnight integration

## 8. Reuse the existing database layer

Overnight already owns:

- database configuration;
- default and named database resolution;
- database object creation;
- database instance caching;
- `CycleDatabase`;
- the Cycle database manager;
- container registration.

Do not add another connection configuration system.

Do not create a second Cycle database manager.

Do not open a second PDO connection for query execution.

The query backend must reuse the exact database instance returned by the
existing `ON\DB\DatabaseManager`.

---

## 9. Public Overnight query facade

Add:

```php
namespace ON\DB\Query;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Query\SelectQuery;

final class QueryDatabase
{
    public function __construct(
        DatabaseManager $databases,
        QueryExecutorFactory $executors,
    );

    public function query(
        DefinitionInterface $source,
        ?string $database = null,
    ): SelectQuery;
}
```

Behavior:

1. resolve the default or named database through the existing
   `DatabaseManager`;
2. obtain one cached neutral executor for that database;
3. create a bound `SelectQuery`;
4. return it.

Examples:

```php
$u = $database->query($users);
```

Named database:

```php
$event = $database->query(
    $analyticsEvents,
    database: 'analytics',
);
```

The facade does not expose Cycle.

### 9.1 Do not modify the existing database interface

Do not add query methods to:

```php
ON\DB\DatabaseInterface
```

That interface is implemented by database resources with different
capabilities.

Query execution remains a separate adapter capability.

### 9.2 No second public database manager

Do not add another full database lifecycle manager.

`QueryDatabase` is a small facade over the existing `DatabaseManager`.

It may cache executors, but it does not own connections or database
configuration.

---

## 10. Executor factory

Add:

```php
namespace ON\DB\Query;

final class QueryExecutorFactory
{
    public function create(
        DatabaseInterface $database,
    ): QueryExecutorInterface;
}
```

Initial behavior:

```php
return match (true) {
    $database instanceof CycleDatabase =>
        new CycleQueryExecutor(...),

    default =>
        throw UnsupportedQueryDatabaseException::forDatabase($database),
};
```

Add a focused Overnight exception:

```php
UnsupportedQueryDatabaseException
```

The factory must not use a service container as a runtime locator.

Dependencies should be constructor-injected or explicitly constructed from the
resolved database.

### 10.1 Executor caching

Cache one executor per resolved database instance or database name.

Do not rebuild translators and conversion helpers for every query execution.

The cache belongs to `QueryDatabase` or `QueryExecutorFactory`, not to the
global container and not to `overnight-data`.

---

## 11. Internal Cycle backend

Recommended layout:

```text
src/DB/Query/
    QueryDatabase.php
    QueryExecutorFactory.php
    Exception/
        UnsupportedQueryDatabaseException.php

    Cycle/
        CycleQueryExecutor.php
        CycleQueryTranslator.php
        CycleExpressionTranslator.php
        CycleTranslationContext.php
        CycleResultMapper.php
```

Cycle classes may appear in these internal backend signatures.

They must not appear in the public facade or `overnight-data`.

Mark backend-only classes `@internal`.

---

## 12. Cycle query executor

```php
final class CycleQueryExecutor implements QueryExecutorInterface
{
    public function fetchAll(
        SelectQuery $query,
    ): array;

    public function fetchOne(
        SelectQuery $query,
    ): ?array;

    public function iterate(
        SelectQuery $query,
    ): iterable;
}
```

Responsibilities:

1. translate the `ON\Data` query into a native Cycle select query;
2. execute through the existing `CycleDatabase` resource;
3. map physical result rows to logical query result rows;
4. wrap backend failures in `QueryExecutionException`.

It must not return the native Cycle query or result.

### 12.1 Cycle remains the SQL authority

Do not generate complete SQL strings in `ON\Data` or Overnight.

Use Cycle's query builder and active driver compiler for:

- identifier quoting;
- parameter binding;
- final statement syntax;
- database-specific limit and offset behavior;
- database execution.

The adapter translates semantic query nodes into Cycle's lower-level query
vocabulary.

---

## 13. Translation context

Correlated subqueries require query identity and source aliases.

Add one internal context that owns:

```text
root query
active query ancestry
query → generated source alias
backend database/driver context
```

Example API:

```php
final class CycleTranslationContext
{
    public function enter(
        SelectQuery $query,
    ): self;

    public function getAlias(
        SelectQuery $query,
    ): string;

    public function contains(
        SelectQuery $query,
    ): bool;
}
```

Exact method names may vary.

Keep the object small and backend-local.

Do not add `QueryScope` to the core query model.

### 13.1 Alias allocation

Assign deterministic query aliases during one translation:

```text
q0
q1
q2
```

The root query is `q0`; nested queries receive aliases in traversal order.

These are backend source aliases, unrelated to projection aliases.

---

## 14. Source resolution

### 14.1 Collections

For a collection query:

- the collection definition name is the initial physical source name unless the
  current definition API provides a more explicit physical source;
- every `FieldRef` uses `FieldInterface::getColumn()` for the physical column.

Do not infer columns from field names when `getColumn()` is available.

### 14.2 Views

A view must resolve through its configured definition source.

If the view has no executable source, throw `UnsupportedQueryException`.

Resolve view-source chains defensively and reject cycles.

A view field still provides its own physical column metadata.

### 14.3 Later source metadata

Do not add a new table/source abstraction in this phase unless the actual
repository proves that collection names cannot represent physical sources.

If an explicit source property is required, add the smallest definition-owned
metadata method and document it separately.

Do not place physical source names in the query AST.

---

## 15. Expression translation

The adapter must support every relation-free expression implemented through
Phase 4.

### 15.1 Field references

Translate a `FieldRef` as:

```text
owner query source alias + field physical column
```

The field owner must be the current query or one of its active ancestors.

Reject:

- unrelated query references;
- sibling query references;
- child references used by a parent.

Correlated ancestor references are valid.

### 15.2 Literals

Use Cycle parameter APIs.

Do not interpolate literal values into SQL or expression strings.

### 15.3 Projection aliases

Translate `AliasedExpression` using the explicit query alias.

Do not mutate field-definition aliases.

### 15.4 Conditions

Support:

- comparison conditions;
- logical `AND`;
- logical `OR`;
- `NOT`;
- `IS NULL`;
- `IS NOT NULL`;
- literal `IN`;
- subquery `IN`;
- `EXISTS`;
- `NOT EXISTS`.

Top-level `WHERE` conditions are combined with `AND`.

Top-level `HAVING` conditions are combined with `AND`.

### 15.5 Aggregates

Support:

- `COUNT(star)`;
- `COUNT(expression)`;
- `COUNT DISTINCT(expression)`;
- `SUM(expression)`.

### 15.6 Semantic value operations

Support:

- `UPPER`;
- `LOWER`;
- `CONCAT`;
- `COALESCE`;
- `ADD`.

The adapter must preserve the semantics defined by the query model.

For operations whose SQL differs by database, use the active Cycle
driver/platform internally.

Do not expose a database-specific operation in the query API.

### 15.7 Subqueries

Support:

- scalar subqueries;
- correlated scalar subqueries;
- `IN` subqueries;
- `EXISTS` subqueries;
- subqueries used in grouping or ordering where supported by the backend.

### 15.8 Grouping, HAVING, sorting, pagination

Support:

- ordered group expressions;
- ordered `HAVING` conditions;
- ordered `Sort` entries;
- ascending and descending directions;
- limit;
- offset.

---

## 16. Execution-time semantic checks

There is still no public validator.

The backend performs the checks required to translate and execute safely.

### 16.1 Root projection

A directly executed root query must contain at least one selection.

Do not silently introduce default selections in Phase 5.

This avoids adding implicit selections and selection provenance before
relation loaders first require them.

### 16.2 Scalar subqueries

A scalar subquery must have exactly one selection.

### 16.3 `IN` subqueries

An `IN` subquery must have exactly one selection.

### 16.4 `EXISTS` subqueries

An `EXISTS` subquery may have zero or more selections.

Do not mutate it by adding a projection.

### 16.5 Result names

Every executed root selection must have a stable logical result name.

Rules:

- unaliased `FieldRef` → field definition name;
- `AliasedExpression` → explicit query alias;
- unaliased computed expression → unsupported for root execution;
- unaliased aggregate → unsupported for root execution;
- unaliased scalar subquery → unsupported for root execution.

Reject duplicate final result names.

Examples requiring aliases:

```php
$u->select(
    $u->name->upper()->as('normalized_name'),
    $u->id->count()->as('total'),
);
```

### 16.6 Aggregate/grouping checks

Phase 5 may reject clearly invalid aggregate/group shapes only when required by
the backend translation.

Do not introduce a second query AST or an execution-plan object.

If robust grouping validation becomes substantial, stop and propose a focused
backend-independent semantic-validation phase rather than hiding a large
validator inside the Cycle adapter.

---

## 17. Parameter conversion

Field types and representations remain the conversion authority.

### 17.1 Field-context literals

When a literal value is directly compared with a `FieldRef`, or appears in an
`IN` list for a `FieldRef`, convert it from:

```text
PhpRepresentation
        ↓
StorageRepresentation
```

using the existing conversion gateway, field type, field-type codecs, and
field metadata.

Do not duplicate date, enum, decimal, bigint, JSON, or custom type conversion
inside the Cycle adapter.

### 17.2 Expression-context literals

When no single field provides conversion context, preserve the PHP literal and
let Cycle/PDO bind supported native values.

Examples include literals inside:

```php
x()->add($field, 10);
x()->concat($first, ' ', $last);
```

A later type-aware semantic-validation/conversion phase may improve operation
result and operand conversion.

### 17.3 Null

Null remains null and is not converted through field codecs.

---

## 18. Result mapping

Public result rows use logical query result names.

### 18.1 Direct fields

For a direct selected field:

- read the backend result value;
- convert from `StorageRepresentation` to `PhpRepresentation` using the
  existing field-type conversion authority;
- expose it under the logical field name or explicit query alias.

### 18.2 Computed expressions

For aggregates, value operations, and scalar subqueries:

- expose the value under the required explicit alias;
- preserve the driver-returned value in Phase 5 unless the expression has a
  concrete field conversion context.

Do not guess field types for computed expressions.

### 18.3 Collections and existing row mapping

Reuse existing collection row/column mapping helpers where they match the
selected result shape.

Do not apply full collection row mapping blindly to aliased or computed
projections.

### 18.4 Iteration

Map rows lazily during `iterate()`.

Do not buffer the entire result set before yielding.

---

## 19. Container integration

Update Overnight's `DatabaseExtension` to register:

```text
QueryDatabase
QueryExecutorFactory
Cycle query backend dependencies
```

The public injectable service is:

```php
ON\DB\Query\QueryDatabase
```

Do not bind application code directly to `CycleQueryExecutor`.

Do not expose Cycle translator services as public application dependencies.

---

## 20. Composer dependencies

### `overnight-data`

Do not add Cycle or Overnight dependencies.

### Overnight

Because Overnight directly uses Cycle Database APIs in the new backend, declare
an explicit compatible `cycle/database` dependency instead of relying only on
a transitive dependency from `cycle/orm`.

Keep PHP compatibility aligned with the existing project.

---

## 21. Public examples

### 21.1 Default database

```php
$u = $database->query($users);

$rows = $u
    ->select(
        $u->id,
        $u->name,
    )
    ->where(
        x()->eq($u->active, true),
    )
    ->orderBy(
        $u->name->asc(),
    )
    ->fetchAll();
```

### 21.2 Named database

```php
$event = $database->query(
    $events,
    database: 'analytics',
);

$rows = $event
    ->select(
        $event->name,
        $event->createdAt,
    )
    ->fetchAll();
```

### 21.3 One row

```php
$row = $u
    ->select(
        $u->id,
        $u->name,
    )
    ->where(
        x()->eq($u->id, 10),
    )
    ->fetchOne();
```

### 21.4 Iteration

```php
foreach (
    $u
        ->select($u->id, $u->name)
        ->orderBy($u->id->asc())
        ->iterate()
    as $row
) {
    // $row is a plain PHP array.
}
```

### 21.5 Detachment

```php
$u = $database->query($users);

$u->select($u->id);

$u->detach();

$u->isExecutable(); // false
$u->getSelections(); // unchanged
```

### 21.6 Standalone model

```php
$u = query($users);

$u
    ->select($u->id)
    ->where(x()->eq($u->active, true));

// The query remains useful for inspection or another adapter.
```

---

## 22. Tests in `overnight-data`

Add fake-executor unit tests for all binding behavior.

Add architecture tests confirming:

- no Cycle namespace is referenced by public core code;
- Composer has no Cycle dependency;
- query execution results use plain PHP types;
- detach preserves the complete query model.

Run the existing quality suite:

```text
composer validate --strict
composer dump-autoload
composer test
composer analyse
composer check-style
composer check
```

---

## 23. Tests in Overnight

### 23.1 Facade and database reuse

Test that:

- `QueryDatabase` resolves the default database through `DatabaseManager`;
- a named database is resolved correctly;
- the existing cached database instance is reused;
- no second Cycle manager or connection is created;
- executors are cached per database.

### 23.2 Unsupported database

Test that a database implementation without a query backend throws
`UnsupportedQueryDatabaseException`.

Do not force `PdoDatabase` to implement Cycle query translation.

### 23.3 SQLite integration suite

Use an in-memory SQLite database through the existing Cycle database layer.

Execute and assert:

- direct field selections;
- logical result names when physical columns differ;
- aliases;
- comparisons;
- logical conditions;
- null conditions;
- literal `IN`;
- subquery `IN`;
- `EXISTS`;
- aggregates;
- star count;
- semantic operations;
- scalar subqueries;
- correlated scalar subqueries;
- grouping;
- `HAVING`;
- mixed ascending/descending sorting;
- limit;
- offset;
- `fetchAll`;
- `fetchOne`;
- lazy iteration.

### 23.4 Invalid query shapes

Test rejection of:

- executable root query with no selections;
- unaliased computed root selections;
- duplicate output names;
- scalar subquery with zero or multiple selections;
- `IN` subquery with zero or multiple selections;
- unrelated field-reference scope;
- sibling field-reference scope;
- child field-reference scope.

### 23.5 Conversion

Test storage/PHP conversion for representative existing field types:

- datetime;
- backed enum;
- JSON;
- decimal;
- bigint;
- nullable values.

Do not add new conversion logic to the Cycle backend.

### 23.6 Failure wrapping

Test that backend exceptions are wrapped in `QueryExecutionException` and
remain available as the previous exception.

Application tests must not need to import a Cycle exception.

---

## 24. Documentation

### `overnight-data`

Add:

```text
docs/3-query/phase-5-bound-execution.md
```

Document:

- bound versus unbound queries;
- `fetchAll`, `fetchOne`, and `iterate`;
- `isExecutable`;
- mutating `detach`;
- the neutral executor contract;
- absence of a Cycle dependency;
- execution-time semantic checks;
- result naming rules;
- deferred relation support and the future loader-tree architecture.

### Overnight

Add framework documentation showing:

- injection of `ON\DB\Query\QueryDatabase`;
- default and named database use;
- plain PHP results;
- no Cycle-facing application API;
- reuse of existing database configuration.

Update README/current-scope sections only after implementation is complete.

---

## 25. Explicitly deferred

Do not implement in Phase 5:

- `load()`;
- `with()`;
- automatic relation participation from expression references;
- relation joins;
- relation traversal;
- relation handlers;
- the loader tree;
- joined or separate relation loading;
- nested result shaping;
- ORM entity hydration;
- writes;
- transactions on the query facade;
- update/delete query models;
- implicit selections;
- selection-entry provenance;
- automatic primary-key inclusion;
- `SelectStatement` or any second fluent select API;
- `ExecutionPlan`, `QueryPlan`, or another plan wrapper;
- a generic planner;
- a generic compiler interface;
- a second backend;
- a standalone connection manager in `overnight-data`;
- query cloning;
- a detached-copy API;
- SQL inspection methods;
- returning native backend queries.

---

## 26. Carried-forward relation architecture

This section records the agreed direction for the next relation phase. It is
not part of Phase 5 implementation.

### 26.1 One `SelectQuery` model everywhere

Application code, root loaders, relation handlers, and separately loaded
relations all use `ON\Data\Query\SelectQuery`.

A separate relation query is created and manipulated normally:

```php
$related = $context->createQuery($relatedCollection);

$related
    ->select($related->id, $related->title)
    ->where(...)
    ->orderBy(...);
```

Do not introduce `SelectStatement` or a second internal fluent API.

### 26.2 Relation query participation

A relation referenced by a query expression participates in root-query
semantics.

Conceptually:

```php
$posts = $u->posts;

$u->where(
    x()->eq($posts->published, true),
);
```

The relation handler must add whatever joins and aliases are required to make
that expression executable.

This is independent of whether related rows are returned.

Do not add `with()` merely as a mandatory declaration before such expressions.
Only introduce `with()` later if a real query-participation use case cannot be
expressed naturally through selection, filtering, ordering, grouping,
aggregation, or existence expressions.

### 26.3 `load()` means include relation data

`load()` means that related data must appear in the result.

It does not inherently mean:

```text
joined loading
```

or:

```text
separate-query loading
```

The relation handler chooses the default strategy based on relation behavior.
A later explicit strategy override may use a model such as:

```php
enum RelationLoadStrategy
{
    case AUTO;
    case JOIN;
    case SEPARATE;
}
```

The same relation may participate in root-query semantics and also be loaded,
possibly with different filters.

### 26.4 One handler configuration method

Do not add separate methods named like:

```php
planWith(...);
planLoad(...);
```

Use one configuration operation with an explicit purpose:

```php
enum RelationPurpose
{
    case QUERY;
    case LOAD;
}

interface RelationHandlerInterface
{
    public function configure(
        RelationContext $context,
        RelationPurpose $purpose,
    ): void;
}
```

`QUERY` and `LOAD` are independent purposes, not mutually exclusive fetch
modes. The same relation handler may be configured for both.

A separate runtime method such as `loadData()` is allowed because it represents
a later lifecycle stage after parent rows and keys exist; it is not a duplicate
planning method.

### 26.5 Loader tree owns execution

Do not introduce an explicit execution-plan object by default.

The root loader and child relation loaders own the workflow directly:

```text
RootLoader
    configure root SelectQuery
    execute root SelectQuery
    parse root rows

    joined relation handler
        mutates the root SelectQuery

    separate relation loader
        extract parent keys
        create another SelectQuery
        execute it
        attach results
        recursively load children
```

A separately loaded relation query is created, configured, and executed by its
loader when the required parent keys are available.

Reconsider a formal plan only for concrete capabilities such as:

- global cross-relation batching;
- parallel scheduling;
- inspection of every deferred query before execution;
- reusable or cached execution strategies;
- centralized optimization across independent loader branches.

### 26.6 Selection provenance

When relation handlers first add fields internally, replace raw selection
storage with a selection entry carrying at least:

```text
explicit
implicit
```

Provenance belongs to each selection entry, not to cached `FieldRef` objects.
The same expression may be both explicitly selected and internally required in
different entries or roles.

An internal convenience method may later be added, such as:

```php
$query->require(
    $query->id,
    SelectionPurpose::RELATION_KEY,
);
```

Do not introduce it in Phase 5 because Phase 5 adds no implicit selections.

### 26.7 Relations and composite keys

Future relation handlers must:

- remain class-based and pluggable;
- use relation metadata rather than Registry special cases;
- include every composite-key component;
- add parent and related key fields as implicit selections where required;
- control joins, additional queries, batching, and result assembly;
- keep Registry free of relation-query peculiarities;
- avoid Cycle ORM loaders entirely.

Cycle Database remains only the statement renderer and executor for each
ordinary `SelectQuery` created by the loader tree.

## 27. Quality and reporting

For both repositories, report:

- actual starting SHA;
- production files added and changed;
- test files added and changed;
- documentation changes;
- Composer dependency changes;
- quality-command results;
- final commit SHA.

Do not combine unrelated framework or mapper changes into the Phase 5 commits.

---

## 28. Stop condition

Phase 5 is complete when:

1. Overnight can create a bound `ON\Data\Query\SelectQuery` from its existing
   default or named database configuration;
2. developers can call `fetchAll()`, `fetchOne()`, or `iterate()` directly on
   that query;
3. unbound queries remain supported;
4. a bound query can be detached without changing its model;
5. Cycle is absent from application-facing APIs;
6. every relation-free Phase 1–4 query feature is executable through the Cycle
   backend;
7. results are plain logical PHP rows;
8. no second connection system, second fluent select API, execution-plan
   object, or generic planning/compiler framework has been introduced.

Stop after that.

Do not begin the relation loader phase or persistence automatically.
