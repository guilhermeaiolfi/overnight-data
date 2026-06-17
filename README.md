# Overnight Data

The definition foundation of a metadata-driven PHP data layer.

`ON\Data` currently ships the standalone definition subsystem extracted from Overnight plus the support layers needed to store, restore, convert, and recursively map data as plain PHP arrays, `stdClass` objects, and public-property DTOs through a composable walker/resolver/writer mapper runtime.

## Status

This repository currently includes:

- the `ON\Data` package scaffold;
- `ON\Data\Support\Dot`;
- `ON\Data\Support\DefinitionNode`;
- the extracted `ON\Data\Definition` subsystem;
- collection-owned primary-key metadata;
- shared `DefinitionInterface` support for collections and views;
- registry-managed `ViewDefinition` and `ViewField` wrappers backed by the same master-array storage;
- the `ON\Data\Key` value object for simple and composite identities;
- the standalone FieldType, representation, and conversion gateway foundation under `ON\Data\Mapper`;
- `MapperManager`, `MappingContext`, `MappingNode`, walkers, writers, field resolvers, mapping-node resolvers, and the fluent `map()` / `MapBuilder` entry point;
- default definition-aware field resolution through `->args($definition)` for scalar conversion;
- generic collection mapping through the same composable runtime;
- recursive array, `stdClass`, and public-property object combinations selected independently by source walker and target writer;
- typed nested DTO properties and PHPDoc-described DTO lists;
- default dotted-key expansion for flat joined rows and request payloads;
- mapper attributes `MapFrom`, `MapTo`, and `Hidden` across nested DTO mapping;
- tests and quality tooling.

Definition arrays are now canonical at creation time. Names are stored only as owner-map keys, every stored wrapper is created by its owner over a final array slot, restored arrays must already be canonical, and old caches using legacy field-level `pk` flags should be discarded and regenerated.

Not implemented yet:

- semantic view fields and expressions;
- query execution;
- persistence and ORM adapters;
- constructor hydration and readonly-target hydration;
- big-integer, decimal, enum, and date-oriented FieldTypes.

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

## Docs

- `docs/definitions.md` covers the current public definition API.
- `docs/extending-definitions.md` covers supported subclass-based extension points.
- `docs/2-field-types-and-mapper.md` covers the implemented scalar FieldType, mapper runtime, and current recursive structural mapping support.
- `docs/2-mappers/phase-5-definition-field-resolver.md` summarizes the definition-aware field resolver phase.
- `docs/2-mappers/phase-6-recursive-mapping-node.md` summarizes the recursive `MappingNode` phase.
- `docs/release-0.1-checklist.md` summarizes the pre-release checklist for the definitions-only package.
