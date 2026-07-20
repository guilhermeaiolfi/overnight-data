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

Or use a runtime backed by the built-in Cycle adapter:

```php
use ON\Data\Database\Cycle\ConnectionConfig;
use ON\Data\Database\Cycle\CycleRuntimeFactory;

$runtime = (new CycleRuntimeFactory())->connect(ConnectionConfig::dsn('sqlite', 'sqlite::memory:'));
$u = $runtime->query($users);
```

`CycleRuntimeFactory::connect()` creates Cycle connection infrastructure internally, then wires ON\Data query and command executors from that connection.

If you already have a configured Cycle database, build the runtime from that adapter connection:

```php
use ON\Data\Database\Cycle\CycleRuntimeFactory;

$runtime = (new CycleRuntimeFactory())->create($cycleDatabase);
```

## Result modes

Bound queries return arrays by default. Object export is opt-in through `to(...)`.

| Call | Result |
| --- | --- |
| `$query->fetchAll()` | `list<array<string, mixed>>` |
| `$query->fetchOne()` | `array<string, mixed>\|null` |
| `$query->fetchOne($identity)` | same, with a temporary primary-key constraint (see below) |
| `$query->iterate()` | `iterable<array<string, mixed>>` |
| `$query->to(stdClass::class)->fetchAll()` | `list<stdClass>` |
| `$query->to(UserRow::class)->fetchAll()` | `list<UserRow>` where `UserRow` is a no-required-constructor public-property class |
| `$query->to(stdClass::class)->writable($session)->fetchAll()` | tracked writable `stdClass` objects |
| `$query->to(UserRow::class)->writable($session)->fetchAll()` | tracked writable mutable public-property DTOs |

Read-only object export also supports lazy iteration: `to(...)->iterate()` yields objects one row at a time. `writable(...)->iterate()` is intentionally unsupported; use `fetchAll()` or `fetchOne()`.

### Identity fetch

`fetchOne($identity)` is sugar for constraining the root collection primary key for that execution only. `$identity` may be a scalar (single-column PK), an associative or positional composite array, or a `Key`. The constraint is tagged `ConditionTag::IDENTITY`, AND-combined with existing user `where()` clauses, and removed after the fetch returns.

Identity fetch requires a collection-root query (`FROM` is a collection, not a derived/`as()` query source). Nested or aliased query sources raise `InvalidArgumentException`. Wrong arity or a `Key` for another collection raises the usual primary-key exceptions from `Collection::getKey()`.

```php
$row = $runtime->query($users)->fetchOne(2);
$row = $runtime->query($users)->where(x()->eq($users->active, true))->fetchOne(2);
$row = $runtime->query($postUser)->fetchOne(['post_id' => 1, 'user_id' => 2]);
$row = $runtime->query($users)->fetchOne($users->getKey(2));
```

Selections tagged `SelectionTag::INTERNAL` are compiled into the query when writable flat projections need hidden identity values. They are stripped from public array and object results.

Writable export requirements:

- requires `to(stdClass::class)` or `to(MutableDto::class)` where the class is concrete and not readonly (mutable public properties);
- requires an explicit `Session`;
- tracks the same object instance returned to the caller (no shadow copy);
- sync reads scalar field paths through `map($representation)->to(stdClass::class)`; relation targets stay live object paths so identity is preserved;
- DTO property names (or `MapFrom`) must match selection aliases / schema paths; avoid `MapTo` that renames those keys on the way back to a bag;
- compiles binding/provenance only for writable export, not for normal array queries or read-only object export;
- compiles one binding per fetch operation and reuses it across rows;
- still creates a distinct `RepresentationState` per object.

## Execution methods

Bound queries expose:

- `fetchAll()`
- `fetchOne(?$identity = null)`
- `iterate()`

`fetchAll()`, `fetchOne()`, and `iterate()` all go through `LoadRuntime` after the query resolves its executor (`getLoadRuntime()`). `LoadRuntime` uses a fast path when there are no relation selections; `iterate()` still rejects relation selections because structured loading may need the full parent batch.

Built-in relation loaders keep ownership of join versus separate-query execution decisions.

## Detaching

`detach()` removes the executor binding in place and returns the same query object.

Selections, conditions, grouping, ordering, pagination, and cached references are preserved.

## Architecture boundary

- `SelectQuery` depends only on `QueryExecutorInterface`.
- Backend translation, semantic checks, SQL generation, and result fetching stay backend-owned.
- The data layer should remain useful even when an optional ORM is absent.
