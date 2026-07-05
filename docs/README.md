# Documentation Index

Start here for the current public documentation. The files linked below are the canonical docs for the current API.

## Definitions

- [`definition-api.md`](./definition-api.md): Canonical registry storage, naming rules, export, restoration, and the public definition wrappers.
- [`definition-extension-guide.md`](./definition-extension-guide.md): Extension points and the storage rules custom definition nodes must follow to round-trip through the registry.

## Mapper

- [`mapper-runtime-guide.md`](./mapper-runtime-guide.md): Mapper runtime concepts, runtime registration, recursive conversion flow, and the canonical reference for field types, representations, codecs, and the `ConversionGateway`.
- [`recursive-mapping-behavior.md`](./recursive-mapping-behavior.md): Recursive branch traversal, resolver precedence, and how mixed source and target structures are mapped.

## Query

- [`query/query-model.md`](./query/query-model.md): Root query construction, field and relation references, selections, joins, and query inspection.
- [`query/expressions-and-conditions.md`](./query/expressions-and-conditions.md): Expressions, aliases, aggregates, semantic operations, and condition construction.
- [`query/grouping-ordering-pagination.md`](./query/grouping-ordering-pagination.md): Grouping, `HAVING`, sorting, and limit/offset pagination.
- [`query/bound-execution.md`](./query/bound-execution.md): Bound execution, detachment, and the neutral database facade.
- [`query/relation-loading.md`](./query/relation-loading.md): Structured relation selection, nested result shaping, loader-owned execution decisions, and current execution limits.

## ORM

- [`orm/foundation.md`](./orm/foundation.md): ORM foundation concepts, record-state persistence model, representation lineage, sync conflicts, and relation state.
- [`orm/persistence.md`](./orm/persistence.md): Scalar ORM persistence pipeline, command planning, Cycle command execution, generated primary-key merge, and write-side limits.
- [`orm/representation-binding.md`](./orm/representation-binding.md): Recursive `RepresentationBinding` model, binding kinds, mapper/query/tracking boundaries, and scalar sync guardrails.
