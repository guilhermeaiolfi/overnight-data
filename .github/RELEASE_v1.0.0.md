# ON\Data 1.0.0

First stable public release of the Overnight Data library.

## Highlights

- **Definitions** — registry-backed collection/view metadata with typed fields and relations
- **Mapper runtime** — recursive mapping and conversion through `map(...)` and `ConversionGateway`
- **Query model** — database-independent `SelectQuery` with expressions, joins, grouping, and pagination
- **Bound execution** — neutral `Database` facade with Cycle-backed runtime
- **Relation loading** — structured nested loading for built-in relation types
- **ORM persistence** — `Session` / `FlushExecutor` scalar and relation write pipeline with affected-row validation
- **Query export** — arrays by default; read-only object export; mutable `stdClass` export with flat projection provenance

## Requirements

- PHP `>=8.3`
- PDO SQLite (or another supported Cycle Database backend) for database examples

## Install

```bash
composer require guilhermeaiolfi/overnight-data:^1.0
```

If Composer cannot resolve the package from Packagist yet:

```bash
composer config repositories.overnight-data vcs https://github.com/guilhermeaiolfi/overnight-data
composer require guilhermeaiolfi/overnight-data:^1.0
```

## Docs

- [Quickstart](https://github.com/guilhermeaiolfi/overnight-data/blob/v1.0.0/docs/quickstart.md)
- [Documentation index](https://github.com/guilhermeaiolfi/overnight-data/blob/v1.0.0/docs/README.md)
- [Upgrade policy](https://github.com/guilhermeaiolfi/overnight-data/blob/v1.0.0/UPGRADE.md)

## Known limits

See the README **Current Limitations** section for intentional non-goals such as lazy loading, repositories, cascade policy, and mutable user-defined class export.
