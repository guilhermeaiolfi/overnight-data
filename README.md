# Overnight Data

`ON\Data` is a standalone PHP data-layer library focused on metadata definitions, value conversion, mapping, and database-independent read-query modeling.

It is independent from the Overnight framework. The package can be consumed on its own today and may be integrated elsewhere later.

## Current Scope

- Definitions: canonical `Registry` storage, collection and view definitions, typed definition wrappers, shared metadata, and class-based extension points.
- Field metadata and conversion: field types, representations, codecs, and the `ConversionGateway` used to convert values through canonical PHP representations.
- Mapper runtime: recursive mapping across arrays, `stdClass`, and public-property DTOs, with definition-aware resolution, `FieldMap` support, delayed object creation for constructor and readonly targets, and reusable mapper/writer/resolver registration through `MapperManager`.
- Query model: database-independent `SelectQuery`, field and relation refs, selections, aliases, semantic value operations, aggregates, subqueries, joins, conditions, grouping, ordering, and pagination.
- Bound execution: optional execution binding through `ON\Data\Database\QueryExecutorInterface`, plus the neutral `Database` facade and `ConnectionConfig`.
- Relation loading: structured relation selection for nested results, loader-owned join or separate-query execution, and parser-backed result assembly for built-in `BelongsTo`, `HasOne`, `HasMany`, `FirstOfMany`, and `M2M` relations.
- ORM persistence: `RecordState`-backed scalar insert/update/delete planning, scalar and relation representation synchronization, configured relation persistence planning, `FlushExecutor` / `Session` orchestration, Cycle-backed command execution, affected-row validation, and simple auto-increment primary-key merge after inserts.
- Query result export: array results by default, read-only `stdClass` and public-property class export, and mutable `stdClass` query export with flat projection provenance.

## Query shape and persistence source are independent

The result object is flat, but mutable query export remembers where each property came from. A property aliased from a related field still updates the underlying table column after `sync()` and `flush()`. This works through mutable query provenance and flat projection adoption.

Given a bound query and a `Session` backed by a command executor (for example, a `CycleCommandExecutor`):

```php
$user = $q
    ->select(
        $q->id,
        $q->company->name->as('name'),
    )
    ->to(stdClass::class)
    ->mutable($session)
    ->fetchOne();

$user->name = 'Dell';

$session->sync($user);
$session->flush();

// Updates companies.name.
```

Mutable export is `stdClass`-only for now. User-defined classes are supported for read-only export only.

## Query result modes

Bound queries return arrays by default. Object export is opt-in through `to(...)`.

```php
$query->fetchAll()
    // list<array<string, mixed>>

$query->fetchOne()
    // array<string, mixed>|null

$query->iterate()
    // iterable<array<string, mixed>>

$query->to(stdClass::class)->fetchAll()
    // list<stdClass>

$query->to(UserRow::class)->fetchAll()
    // list<UserRow>
    // UserRow is a no-required-constructor public-property class

$query->to(stdClass::class)->mutable($session)->fetchAll()
    // tracked mutable stdClass objects
```

Read-only object export also supports lazy iteration: `to(...)->iterate()` yields objects one row at a time. `mutable(...)->iterate()` is intentionally unsupported; use `fetchAll()` or `fetchOne()`.

Selections tagged `SelectionTag::INTERNAL` are used for hidden identity values required by mutable flat projections. They are stripped from public array and object results.

See [`docs/query/bound-execution.md`](docs/query/bound-execution.md) and [`docs/query/query-model.md`](docs/query/query-model.md) for execution and export details.

## Read-only DTO export

```php
final class UserRow
{
    public int $id;
    public string $name;
}

$q = $database->query($users);

$rows = $q
    ->select($q->id, $q->name)
    ->to(UserRow::class)
    ->fetchAll();
```

Public-property class export requirements:

- `stdClass` is supported.
- User-defined public-property classes are supported for read-only export.
- Classes must be instantiable without required constructor arguments.
- Public result keys must match public properties.
- Nested typed object properties may be materialized into their declared classes when supported.
- Array relation/list properties receive arrays of `stdClass` items unless explicitly supported otherwise.
- Mutable export is `stdClass`-only for now.

## Mutable export requirements

- Mutable export requires `to(stdClass::class)`.
- Mutable export requires an explicit `Session`.
- Binding and provenance are compiled only for mutable export, not for normal fast array queries or read-only object export.
- One binding is compiled per fetch operation and reused across rows.
- Each object still gets its own `RepresentationState`.

## Current Limitations

- Structured relation loading supports the built-in `BelongsTo`, `HasOne`, `HasMany`, `FirstOfMany`, and `M2M` relation types.
- Built-in `FirstOfMany` loading is separate-query-only, uses windowed ranking on supported SQL backends, and requires deterministic relation-level `orderBy` metadata; JOIN loading is intentionally unsupported.
- No automatic relation cascade writes or broad orphan-removal policy unless explicitly implemented by a relation planner.
- `Session::flush()` runs inside a database transaction when the command executor implements `TransactionalCommandExecutorInterface` (including `CycleCommandExecutor`). There is no separate transaction API on `Session`.
- No optimistic locking or stale-row revision conflict handling beyond representation sync baseline checks.
- No lazy loading.
- No repositories, `EntityManager`, `UnitOfWork`, events, proxies, or generated model layer.
- No full database-default refresh beyond simple auto-increment primary keys.
- Mutable user-defined class export is not supported yet.
- Mutable iteration is not supported yet.
- Flat projection provenance is for mutable `stdClass` query export.

## Namespace

Production code autoloads from the `ON\Data\` namespace.

## Installation

```bash
composer config repositories.overnight-data vcs <repository-url>
composer require guilhermeaiolfi/overnight-data:dev-main
```

## Quality Commands

```bash
composer install
composer validate --strict
composer dump-autoload
composer test
composer analyse
composer check-style
composer check
```

## Documentation

- `docs/README.md` is the documentation index.
- `docs/definition-api.md` documents the canonical registry and definition API.
- `docs/definition-extension-guide.md` documents supported definition extension points and storage rules.
- `docs/mapper-runtime-guide.md` documents the mapper runtime, conversion system, and runtime registration surface.
- `docs/recursive-mapping-behavior.md` documents recursive mapping behavior and runtime traversal rules.
- `docs/query/query-model.md` documents the current query model, result export, and reference types.
- `docs/query/expressions-and-conditions.md` documents query expressions, aliases, and condition building.
- `docs/query/grouping-ordering-pagination.md` documents grouping, ordering, and pagination.
- `docs/query/bound-execution.md` documents bound execution, result modes, and the neutral database facade.
- `docs/query/relation-loading.md` documents relation selection, nested loading, and loader ownership boundaries.
- `docs/orm/foundation.md` documents ORM foundation concepts, state primitives, representation lineage, sync conflicts, and relation guardrails.
- `docs/orm/persistence.md` documents the ORM persistence pipeline, affected-row validation, Cycle command executor boundary, generated-key support, relation persistence planning boundary, and write-side limitations.
- `docs/orm/representation-binding.md` documents representation binding, flat projection adoption, mapper/query/tracking boundaries, and scalar sync guardrails.
