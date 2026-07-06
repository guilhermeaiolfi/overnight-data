# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Preserve internal mutable-projection identity columns in Cycle query results so flat related-field export can adopt and flush updates against a real database executor.

### Documentation

- Clarified v1.0 wording in `docs/orm/foundation.md` and fixed a formatting issue in `README.md`.
- Added smoke tests that mirror `docs/quickstart.md`, ORM persistence, and mutable `stdClass` projection examples.

## [1.0.0] - 2026-07-05

First stable public release of `guilhermeaiolfi/overnight-data`.

### Added

- **Definitions** — canonical `Registry` storage, collection/view wrappers, typed fields and relations, and extension points for custom definition nodes.
- **Conversion & mapping** — field types, representations, codecs, `ConversionGateway`, and recursive mapping for arrays, `stdClass`, and public-property DTOs through `map(...)`.
- **Query model** — database-independent `SelectQuery` with field/relation refs, selections, aliases, expressions, aggregates, subqueries, joins, conditions, grouping, ordering, and pagination.
- **Bound execution** — neutral `Database` facade, `ConnectionConfig`, and Cycle-backed `QueryExecutorInterface` integration.
- **Relation loading** — structured relation selection with loader-owned join or separate-query execution for built-in `BelongsTo`, `HasOne`, `HasMany`, `FirstOfMany`, and `M2M` relations.
- **Query result export** — array results by default; read-only `stdClass` and public-property class export; mutable `stdClass` export with flat projection provenance through `Session`.
- **ORM persistence** — `RecordState`-backed scalar insert/update/delete planning, representation sync, relation persistence planning, `FlushExecutor` / `Session` orchestration, affected-row validation, and simple auto-increment primary-key merge after inserts.

### Documentation

- Public docs under `docs/` for definitions, mapper runtime, query model, bound execution, relation loading, and ORM foundations.
- [`docs/quickstart.md`](docs/quickstart.md) walkthrough for a first end-to-end project.
- [`UPGRADE.md`](UPGRADE.md) backward-compatibility policy for the 1.x line.

### Notes

- Requires PHP `>=8.3`.
- Runtime database integration currently ships through Cycle Database.
- See README **Current Limitations** for intentional non-goals such as lazy loading, repositories, and full cascade policy.

[1.0.0]: https://github.com/guilhermeaiolfi/overnight-data/releases/tag/v1.0.0
