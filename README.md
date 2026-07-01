# Overnight Data

`ON\Data` is a standalone PHP data-layer library focused on metadata definitions, value conversion, mapping, and database-independent read-query modeling.

It is independent from the Overnight framework. The package can be consumed on its own today and may be integrated elsewhere later.

## Current Scope

- Definitions: canonical `Registry` storage, collection and view definitions, typed definition wrappers, shared metadata, and class-based extension points.
- Field metadata and conversion: field types, representations, codecs, and the `ConversionGateway` used to convert values through canonical PHP representations.
- Mapper runtime: recursive mapping across arrays, `stdClass`, and public-property DTOs, with definition-aware resolution, `FieldMap` support, and reusable mapper/writer/resolver registration through `MapperManager`.
- Query model: database-independent `SelectQuery`, field and relation refs, selections, aliases, semantic value operations, aggregates, subqueries, joins, conditions, grouping, ordering, and pagination.
- Bound execution: optional execution binding through `ON\Data\Database\QueryExecutorInterface`, plus the neutral `Database` facade and `ConnectionConfig`.
- Relation loading: structured relation selection for nested results, loader-owned join or separate-query execution, and parser-backed result assembly for built-in `BelongsTo`, `HasOne`, `HasMany`, and `M2M` relations.

## Current Limitations

- No persistence or write-side unit-of-work layer.
- No ORM adapter layer.
- No constructor hydration or readonly-target hydration in the mapper runtime.
- Structured relation loading supports the built-in `BelongsTo`, `HasOne`, `HasMany`, and `M2M` relation types.
- Built-in `FirstOfMany` structured loading is not implemented yet.

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
- `docs/3-query/query-model.md` documents the current query model and reference types.
- `docs/3-query/expressions-and-conditions.md` documents query expressions, aliases, and condition building.
- `docs/3-query/grouping-ordering-pagination.md` documents grouping, ordering, and pagination.
- `docs/3-query/bound-execution.md` documents bound execution and the neutral database facade.
- `docs/3-query/relation-loading.md` documents relation selection, nested loading, and loader ownership boundaries.
