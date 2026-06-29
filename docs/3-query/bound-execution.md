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

## Execution methods

Bound queries expose:

- `fetchAll()`
- `fetchOne()`
- `iterate()`

Without relation selections, these delegate directly to `QueryExecutorInterface`.

With relation selections:

- `fetchAll()` and `fetchOne()` route through `LoadRuntime`;
- `iterate()` is intentionally rejected because structured loading may need the full parent batch.

## Detaching

`detach()` removes the executor binding in place and returns the same query object.

Selections, conditions, grouping, ordering, pagination, and cached references are preserved.

## Architecture boundary

- `SelectQuery` depends only on `QueryExecutorInterface`.
- Backend translation, semantic checks, SQL generation, and result fetching stay backend-owned.
- The data layer should remain useful even when an optional ORM is absent.
