# Documentation Index

Start here for the current public documentation. The files linked below are the canonical docs for the current API.

## Getting started

- [`quickstart.md`](./quickstart.md): First end-to-end walkthrough using definitions, mapping, bound queries, and relation loading.
- [`../CHANGELOG.md`](../CHANGELOG.md): Release history.
- [`../UPGRADE.md`](../UPGRADE.md): Backward-compatibility policy for the 1.x line.

## Definitions

- [`definition-api.md`](./definition-api.md): Canonical registry storage, naming rules, export, restoration, and the public definition wrappers.
- [`definition-extension-guide.md`](./definition-extension-guide.md): Extension points and the storage rules custom definition nodes must follow to round-trip through the registry.

## Mapper

- [`mapper-runtime-guide.md`](./mapper-runtime-guide.md): Mapper runtime concepts, runtime registration, recursive conversion flow, and the canonical reference for field types, representations, codecs, and the `ConversionGateway`.
- [`recursive-mapping-behavior.md`](./recursive-mapping-behavior.md): Recursive branch traversal, resolver precedence, and how mixed source and target structures are mapped.

## Query

- [`query/query-model.md`](./query/query-model.md): Root query construction, field and relation references, selections, result export, joins, and query inspection.
- [`query/expressions-and-conditions.md`](./query/expressions-and-conditions.md): Expressions, aliases, aggregates, semantic operations, and condition construction.
- [`query/grouping-ordering-pagination.md`](./query/grouping-ordering-pagination.md): Grouping, `HAVING`, sorting, and limit/offset pagination.
- [`query/bound-execution.md`](./query/bound-execution.md): Bound execution, result modes, detachment, and the data runtime.
- [`query/relation-loading.md`](./query/relation-loading.md): Structured relation selection, nested result shaping, loader-owned execution decisions, and current execution limits.

## ORM

- [`orm/foundation.md`](./orm/foundation.md): ORM foundation concepts, record-state persistence model, representation lineage, sync conflicts, and relation state.
- [`orm/persistence.md`](./orm/persistence.md): Scalar ORM persistence pipeline, command planning, affected-row validation, Cycle command execution, generated primary-key merge, and write-side limits.
- [`orm/representation-binding.md`](./orm/representation-binding.md): Recursive `RepresentationBinding` model, flat projection adoption, binding kinds, mapper/query/tracking boundaries, and scalar sync guardrails.
- [`orm/mutable-select-query-projections.md`](./orm/mutable-select-query-projections.md): Mutable `SelectQuery` projection provenance, flattened related-field updates, relation intent from queried objects, `identify()`, and current projection boundaries.
- [`orm/manual-mutable-projections.md`](./orm/manual-mutable-projections.md): Manual non-executing `from()` / `properties()` projections for objects that need explicit record identities and relation item intent.
