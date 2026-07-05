# Bound Execution

`SelectQuery` can be either unbound or bound to a neutral query executor.

## Unbound queries

The `query()` helper creates an unbound query:

```php
$u = query($users);

$u->isExecutable(); // false
```

Calling `fetchAll()`, `fetchOne()`, or `iterate()` on an unbound query throws `QueryNotExecutableException`.

## Bound queries

You can bind an executor directly:

```php
use ON\Data\Query\SelectQuery;

$u = new SelectQuery($users, $executor);
```

Or use the neutral database facade:

```php
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Database;

$database = Database::connect(ConnectionConfig::sqliteMemory());
$u = $database->query($users);
```

`Database::connect()` currently delegates to the built-in Cycle backend, but the public surface remains `ON\Data\Database`.

## Result modes

Bound queries return arrays by default. Object export is opt-in through `to(...)`.

| Call | Result |
| --- | --- |
| `$query->fetchAll()` | `list<array<string, mixed>>` |
| `$query->fetchOne()` | `array<string, mixed>\|null` |
| `$query->iterate()` | `iterable<array<string, mixed>>` |
| `$query->to(stdClass::class)->fetchAll()` | `list<stdClass>` |
| `$query->to(UserRow::class)->fetchAll()` | `list<UserRow>` where `UserRow` is a no-required-constructor public-property class |
| `$query->to(stdClass::class)->mutable($session)->fetchAll()` | tracked mutable `stdClass` objects |

Read-only object export also supports lazy iteration: `to(...)->iterate()` yields objects one row at a time. `mutable(...)->iterate()` is intentionally unsupported; use `fetchAll()` or `fetchOne()`.

Selections tagged `SelectionTag::INTERNAL` are compiled into the query when mutable flat projections need hidden identity values. They are stripped from public array and object results.

Mutable export requirements:

- requires `to(stdClass::class)`;
- requires an explicit `Session`;
- compiles binding/provenance only for mutable export, not for normal array queries or read-only object export;
- compiles one binding per fetch operation and reuses it across rows;
- still creates a distinct `RepresentationState` per object.

## Execution methods

Bound queries expose:

- `fetchAll()`
- `fetchOne()`
- `iterate()`

Without relation selections, these delegate directly to `QueryExecutorInterface`.

With relation selections:

- `fetchAll()` and `fetchOne()` route through `LoadRuntime`;
- `iterate()` is intentionally rejected because structured loading may need the full parent batch.

Built-in relation loaders keep ownership of join versus separate-query execution decisions.

## Detaching

`detach()` removes the executor binding in place and returns the same query object.

Selections, conditions, grouping, ordering, pagination, and cached references are preserved.

## Architecture boundary

- `SelectQuery` depends only on `QueryExecutorInterface`.
- Backend translation, semantic checks, SQL generation, and result fetching stay backend-owned.
- The data layer should remain useful even when an optional ORM is absent.
