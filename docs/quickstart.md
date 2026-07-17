# Quickstart

This walkthrough shows the main moving parts of `ON\Data` in one place: definitions, mapping, bound queries, and relation loading.

It assumes PHP 8.3+, Composer, and SQLite with PDO available for the database section.

## 1. Install

```bash
composer require guilhermeaiolfi/overnight-data:^1.0
```

If Composer cannot resolve the package yet, add the GitHub repository explicitly:

```bash
composer config repositories.overnight-data vcs https://github.com/guilhermeaiolfi/overnight-data
composer require guilhermeaiolfi/overnight-data:^1.0
```

## 2. Define collections

Metadata lives in a single `Registry`. Collections, fields, and relations are plain-array-backed wrappers.

```php
use ON\Data\Definition\Registry;

$registry = new Registry();

$registry->collection('users')
    ->table('users')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->field('name', 'string')->end()
    ->field('active', 'bool')->end()
    ->hasMany('posts', 'posts')
        ->innerKey('id')
        ->outerKey('user_id')
        ->end();

$registry->collection('posts')
    ->table('posts')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->field('user_id', 'int')->column('user_id')->end()
    ->field('title', 'string')->end()
    ->field('published', 'bool')->end();

$users = $registry->getCollection('users');
```

## 3. Map external input through definitions

Use `map(...)` to convert wire/storage shapes into PHP values using field metadata.

```php
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\WireRepresentation;

$normalized = map([
    'id' => '10',
    'name' => 'Ada',
    'active' => '1',
])
    ->from(WireRepresentation::class)
    ->args($users)
    ->to([]);

// ['id' => 10, 'name' => 'Ada', 'active' => true]
```

The same registry metadata drives SQL mapping during query execution.

## 4. Connect and run a bound query

`CycleRuntimeFactory::connect()` returns an ON\Data runtime backed by the built-in Cycle Database adapter.

```php
use ON\Data\Database\Cycle\ConnectionConfig;
use ON\Data\Database\Cycle\CycleRuntimeFactory;
use function ON\Data\Query\x;

$runtime = (new CycleRuntimeFactory())->connect(ConnectionConfig::dsn('sqlite', 'sqlite::memory:'));

$query = $runtime->query($users);

$rows = $query
    ->select($query->id, $query->name)
    ->where(x()->eq($query->active, true))
    ->orderBy($query->id->asc())
    ->fetchAll();
```

This example assumes matching SQLite tables already exist for the definitions above.

## 5. Load nested relations

Pass relation refs to `select()`, or configure them on the cached ref before fetch. Bare `$query->posts` loads all visible related fields.

```php
$query = $runtime->query($users);

$rows = $query
    ->select(
        $query->id,
        $query->name,
        $query->posts
            ->fields('id', 'title')
            ->where(x()->eq($query->posts->published, true)),
    )
    ->orderBy($query->id->asc())
    ->fetchAll();

// Example shape:
// [
//     ['id' => 1, 'name' => 'Ada', 'posts' => [
//         ['id' => 10, 'title' => 'Hello'],
//     ]],
// ]
```

Built-in loaders choose join or separate-query execution. See [`query/relation-loading.md`](./query/relation-loading.md) for strategy, visibility, and current limits.

## 6. Export objects instead of arrays

Object export is opt-in through `to(...)`.

```php
final class UserRow
{
    public int $id;
    public string $name;
}

$userQuery = $runtime->query($users);

$objects = $userQuery
    ->select($userQuery->id, $userQuery->name)
    ->to(UserRow::class)
    ->fetchAll();
```

Writable tracked export for persistence workflows requires `stdClass` and an explicit `Session`. See [`query/bound-execution.md`](./query/bound-execution.md) and [`orm/persistence.md`](./orm/persistence.md).

## Next steps

- [`definition-api.md`](./definition-api.md) — registry storage and naming rules.
- [`mapper-runtime-guide.md`](./mapper-runtime-guide.md) — conversion gateway and recursive mapping.
- [`query/query-model.md`](./query/query-model.md) — query construction and result export.
- [`orm/foundation.md`](./orm/foundation.md) — ORM state model and current limits.
- [`../UPGRADE.md`](../UPGRADE.md) — upgrade expectations (no broad compatibility promise).
