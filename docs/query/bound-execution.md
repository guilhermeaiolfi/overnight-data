# Bound Execution

`SelectQuery` can be either unbound or bound to a neutral query executor.

## Unbound queries

The `query()` helper creates an unbound query:

```php
$u = query($users);

$u->isExecutable(); // false
```

Calling `fetchAll()`, `fetchOne()`, `iterate()`, or `count()` on an unbound query throws `QueryNotExecutableException`.

## Bound queries

You can bind an executor directly:

```php
use ON\Data\Query\SelectQuery;

$u = new SelectQuery($users, $executor);
```

Use the built-in SQL runtime to open a PDO connection from a DSN.

```php
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\SqlRuntimeFactory;

$runtime = (new SqlRuntimeFactory())->connect(new ConnectionConfig('sqlite:/path/to/app.sqlite'));
$u = $runtime->query($users);
```

`SqlRuntimeFactory::connect()` creates a PDO connection and shares it across the query and command executors.

Pass an existing PDO handle to reuse its connection settings, transaction, or in-memory SQLite database.

```php
use ON\Data\Database\SqlRuntimeFactory;

$runtime = (new SqlRuntimeFactory())->create($pdo);
```

The PDO wrapper enables exception mode, preserves SQL result labels with `PDO::CASE_NATURAL`, and uses native prepares where supported.

You can also pass an ON\Data `ConnectionInterface`. Its methods consume SQL with positional `?` placeholders and matching parameter lists. DML returning rows uses the fetch methods. An adapter must keep a write, its generated ID, and any follow-up read on the same physical connection. Its transaction callback should participate in an existing transaction and roll back its own writes on failure.

Use `new SqlRuntimeFactory(insertReturning: false)` if a custom SQLite, MariaDB, or SQL Server connection needs insert readback by key. A MariaDB adapter can opt in with `insertReturning: true` when its server supports `INSERT ... RETURNING`.

The compiler supplies dialects for SQLite, PostgreSQL, MySQL, MariaDB, and SQL Server. PDO execution requires the corresponding PHP extension (`pdo_sqlite`, `pdo_pgsql`, `pdo_mysql`, or `pdo_sqlsrv`). PDO yields rows lazily to ON\Data, and the driver may buffer its result set.

### Custom SQL dialects

Implement `ON\Data\Database\Sql\Dialect\DialectInterface` to supply custom quoting or SQL syntax. Pass the implementation to either executor using its `dialect:` argument.

```php
use ON\Data\Database\Sql\SqlCommandExecutor;
use ON\Data\Database\Sql\SqlQueryExecutor;

$queries = new SqlQueryExecutor($connection, $gateway, dialect: $customDialect);
$commands = new SqlCommandExecutor($connection, dialect: $customDialect);
```

Return an `ON\Data\Database\Sql\SqlFragment` from the SELECT prefix and pagination methods. The fragment pairs SQL text with its positional parameters in placeholder order.

## Result modes

Bound queries return arrays by default. Object export is opt-in through `to(...)`.

Queries created by `DataRuntime::query()` use the runtime's `ConversionGateway` for object export, including when reopened from a `RepresentationSchema`. Standalone `SelectQuery` instances accept an optional `gateway:` argument and use `Mapping::getDefaultGateway()` when it is omitted.

`to($class)` accepts targets supported by a writer on that gateway, including interfaces and abstract classes handled by custom writers. Prepend a custom writer if it should take priority over a built-in writer that also accepts the target.

`SelectQuery::count()` always executes as a scalar aggregate path: it does not apply `to(...)`, `writable(...)`, or relation loading on the count copy. See [`query-model.md`](./query-model.md#counting-matching-rows).

| Call | Result |
| --- | --- |
| `$query->fetchAll()` | `list<array<string, mixed>>` |
| `$query->fetchOne()` | `array<string, mixed>\|null` |
| `$query->fetchOne($identity)` | same, with a temporary primary-key constraint (see below) |
| `$query->iterate()` | `iterable<array<string, mixed>>` |
| `$query->to(stdClass::class)->fetchAll()` | `list<stdClass>` |
| `$query->to(UserRow::class)->fetchAll()` | `list<UserRow>` with DTO-derived projection when no explicit `select()` / relation selection was configured |
| `$query->to(stdClass::class)->writable($session)->fetchAll()` | tracked writable `stdClass` objects |
| `$query->to(UserRow::class)->writable($session)->fetchAll()` | tracked writable mutable public-property DTOs (including DTO-derived flat fields and nested relations) |

On a collection-root query with its default root selection, `to(Dto::class)` derives the projection from the DTO's visible public properties. Use `#[MapFrom('company.name')]` for aliases and flat related fields, a nested DTO type for a to-one relation, and `#[MapCollection(ChildDto::class)]` for a to-many relation. The collection attribute also works on readonly promoted properties and takes precedence over `@var list<ChildDto>` or `@param list<ChildDto>`. The compiler skips `#[Hidden]` and unmapped defaulted properties.

`stdClass`, interfaces, abstract classes, and classes with no declared visible public properties keep the default root scalar selection. Explicit `select()` or relation selections before `to(...)` take precedence.

Read-only object export also supports lazy iteration: `to(...)->iterate()` yields objects one row at a time. `writable(...)->iterate()` is intentionally unsupported; use `fetchAll()` or `fetchOne()`.

### Identity fetch

`fetchOne($identity)` is sugar for constraining the root collection primary key for that execution only. `$identity` may be a scalar (single-column PK), an associative or positional composite array, or a `Key`. The constraint is tagged `ConditionTag::IDENTITY` and AND-combined with existing user `where()` clauses on an execution copy. It never changes the reusable query's conditions, including when an executor makes a nested identity fetch or throws.

Identity fetch requires a collection-root query (`FROM` is a collection, not a derived/`as()` query source). Nested or aliased query sources raise `InvalidArgumentException`. Wrong arity or a `Key` for another collection raises the usual primary-key exceptions from `Collection::getKey()`.

```php
$row = $runtime->query($users)->fetchOne(2);
$row = $runtime->query($users)->where(x()->eq($users->active, true))->fetchOne(2);
$row = $runtime->query($postUser)->fetchOne(['post_id' => 1, 'user_id' => 2]);
$row = $runtime->query($users)->fetchOne($users->getKey(2));
```

When assemble runs (aliases, flats, or relation loads), implicit source primary keys land on a same-level identity overlay passed to writable `track()`, not on the public row. Explicit public paths such as `$users->company->id` stay public (`company.id` as a selected field). Root INTERNAL/SQL_ONLY keys are masked from public array and object results on the fast path and `iterate()`. `writable()` does not add identity columns. A writable fetch whose overlay lacks the root primary key is not tracked.

Writable export requirements:

- Requires `to(...)`. The default object writer requires a mutable target for writable export. A custom writer can hydrate its own targets when the session's mapper can read their schema paths.
- requires an explicit `Session`;
- tracks the same object instance returned to the caller (no shadow copy);
- requires the root primary key to be present on the fetched row (`writable()` does not add it; results without identity are never tracked);
- Hydration and sync read scalar and relation paths through the session's Mapper gateway.
- Selection aliases and schema paths must match the names exposed by the Mapper. DTOs can use `MapFrom` for export and `MapTo` or a custom mapper for reads.
- `map($object, gateway: $gateway)->shallow()->to([])` reads one object level and preserves nested values as written, so relation targets and items keep their original object identity.
- compiles binding/provenance only for writable export, not for normal array queries or read-only object export;
- compiles one binding per fetch operation and reuses it across rows;
- still creates a distinct `RepresentationState` per object.

## Execution methods

Bound queries expose:

- `fetchAll()`
- `fetchOne(?$identity = null)`
- `iterate()`
- `count()`

`fetchAll()`, `fetchOne()`, and `iterate()` each create a local `LoadRuntime` after resolving the query's executor. The query does not cache the runtime, parser results, or identity rows. `LoadRuntime` uses a fast path when no row assembly is needed; `iterate()` still rejects relation selections because structured loading may need the full parent batch.

### Reusing a query

Fetch preparation uses the existing `SelectQuery::copy()` for writable queries, relation references, joins, aliases requiring assembly, and identity fetches. Loader-generated joins and selections belong to that execution copy. Changing a relation from `join()` to `separate()` after a fetch therefore starts from the authored query rather than a previously prepared SQL shape. Failed preparation or execution also leaves the authored shape intact.

Plain root reads without those features still pass the original query instance to the executor. Custom executors should inspect the query they receive: a prepared fetch receives its execution copy. Writable handlers receive that same per-fetch query for both `prepare()` and `track()`, along with the unchanged preparation-token API. They still track the exact object instances returned to the caller.

`getFetchSchema()` remains available on the reusable query. It exposes the schema from the last successfully prepared `fetchAll()` or `fetchOne()`, even when execution returns no rows or fails. Plain reads that skip schema compilation leave it null. It is diagnostic metadata, not a retained load runtime. Iteration keeps the existing raw-row semantics and does not compile a fetch schema.

`count()` keeps caller-authored joins and filters while ignoring relation load selections. A prior fetch no longer changes the count by leaving loader-generated joins on the reusable query. An inner joined load can therefore return fewer roots than `count()`; express required relation membership with a filter or explicit join when the count should include that restriction.

`count()` copies and reshapes the live query, then runs ordinary `fetchOne()` on an aggregate select. Bound executors must translate ordinary aggregate expressions. Grouped and composite-key counts also emit a derived `FROM` (`SelectQuery::as(...)`) that the executor must support. There is no executor-specific count API.

Built-in relation loaders keep ownership of join versus separate-query execution decisions.

## Detaching

`detach()` removes the executor binding in place and returns the same query object.

Selections, conditions, grouping, ordering, pagination, and cached references are preserved.

## Architecture boundary

- `SelectQuery` depends only on `QueryExecutorInterface`.
- Backend translation, semantic checks, SQL generation, and result fetching stay backend-owned.
- The data layer should remain useful even when an optional ORM is absent.

The necessary read steps (declare, path vs fetch, convert, assemble, materialize, optional tracking) are in [`../read-write.md`](../read-write.md).
