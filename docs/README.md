# Documentation Index

Docs describe how the code works **today**. They are not a support contract or stability guarantee — see [`../UPGRADE.md`](../UPGRADE.md).

The files linked below are the current reference material.

## Getting started

- [`quickstart.md`](./quickstart.md): First end-to-end walkthrough using definitions, mapping, bound queries, and relation loading.
- [`../CHANGELOG.md`](../CHANGELOG.md): Release history.
- [`../UPGRADE.md`](../UPGRADE.md): What docs mean, upgrade expectations, no compatibility promise.

## Architecture proposals

Proposed designs (not implemented until accepted and built):

- [`architecture/proposals/0001-representation-schema-as-reusable-model.md`](./architecture/proposals/0001-representation-schema-as-reusable-model.md): Extend `RepresentationSchema` into a reusable select shape (`query($schema)`), including expression nodes.
- [`architecture/proposals/0002-recursive-projection-levels.md`](./architecture/proposals/0002-recursive-projection-levels.md): Unify root and nested relation projections so every level shares one selection model (should inform / precede 0001).
- [`architecture/proposals/0003-load-graph-and-schema-as-place.md`](./architecture/proposals/0003-load-graph-and-schema-as-place.md): Split fetch (`LoadGraph`) from place (`RepresentationSchema`); parser hydrates LoadGraph only.

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
- [`orm/representation-schema.md`](./orm/representation-schema.md): Recursive `RepresentationSchema` model, flat projection adoption, schema kinds, mapper/query/tracking boundaries, and scalar sync guardrails.
- [`orm/writable-select-query-projections.md`](./orm/writable-select-query-projections.md): Writable `SelectQuery` projection provenance, flattened related-field updates, relation intent from queried objects, `identify()`, and current projection boundaries.
- [`orm/session-save-api.md`](./orm/session-save-api.md): `update` / `create` / `detach` / `sync` / `flush`, `SelectQuery::projection()`, nested and flat intents, and `RepresentationIntentStore`.
