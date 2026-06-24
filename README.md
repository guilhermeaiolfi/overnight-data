# Overnight Data

`ON\Data` is the definition and mapper foundation for a metadata-driven PHP data layer.

The package currently ships the standalone definition subsystem extracted from Overnight, together with the support layers needed to store, restore, convert, and recursively map data across plain PHP arrays, `stdClass` objects, and public-property DTOs.

## Current Scope

This repository currently includes:

- the `ON\Data` package scaffold;
- `ON\Data\Support\Dot`;
- `ON\Data\Support\DefinitionNode`;
- the extracted `ON\Data\Definition` subsystem;
- collection-owned primary-key metadata;
- shared `DefinitionInterface` support for collections and views;
- registry-managed `ViewDefinition` and `ViewField` wrappers backed by the same master-array storage;
- the `ON\Data\Key` value object for simple and composite identities;
- the standalone FieldType, representation, field-type codec, and conversion gateway foundation under `ON\Data\Mapper`;
- `MapperManager`, `MappingContext`, `MappingNode`, one-level Mappers, writers, node resolvers, and the fluent `map()` / `MapBuilder` entry point;
- default definition-aware node resolution through `->args($definition)` for scalar conversion and relation branches;
- ad-hoc path-based scalar metadata through `FieldMap::fromArray()` and `MapBuilder::fieldMap()`;
- the Phase 1, Phase 2, and Phase 3 `ON\Data\Query` read-query model for database-independent select expressions, semantic value operations, aggregate nodes, scalar subqueries, query-local named expressions, and condition trees;
- generic collection mapping through the same composable runtime;
- recursive array, `stdClass`, and public-property object combinations selected independently by source Mapper and target writer, with cycle checks applied at recursive mapper dispatch;
- typed nested DTO properties and PHPDoc-described DTO lists;
- automatic backed-enum and immutable-datetime reflection for public DTO properties;
- exact numeric `decimal` and `bigint` field types using canonical strings;
- default dotted-key expansion for flat joined rows and request payloads;
- mapper attributes `MapFrom`, `MapTo`, and `Hidden` across nested DTO mapping;
- tests and quality tooling.

Definition arrays are canonical at creation time. Names are stored only as owner-map keys, every stored wrapper is created by its owner over a final array slot, restored arrays must already be canonical, and older caches using legacy field-level `pk` flags should be regenerated.

## Current Limitations

Not implemented yet:

- semantic view fields and expressions;
- query execution, SQL compilation, and adapter integration;
- persistence and ORM adapters;
- constructor hydration and readonly-target hydration.

## Namespace

Production code autoloads from the `ON\Data\` namespace.

## Installation

During development, install from the repository with Composer:

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

- `docs/definition-api.md` documents the canonical registry and public definition API.
- `docs/definition-extension-guide.md` documents supported extension points and storage rules for custom definition nodes.
- `docs/mapper-runtime-guide.md` documents field types, representations, conversion flow, and the recursive mapper runtime.
- `docs/3-query/phase-1-query-model.md` documents the Phase 1 database-independent query model.
- `docs/3-query/phase-2-aggregate-subqueries.md` documents Phase 2 aggregate, subquery, `EXISTS`, and `IN` query modeling.
- `docs/3-query/phase-3-semantic-value-operations.md` documents Phase 3 semantic value operations and query-local named expression lookup.
