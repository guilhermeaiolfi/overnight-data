# Documentation Index

Start here for the current public documentation. The files linked below are the canonical docs for the current API.

## Definitions

- [`definition-api.md`](./definition-api.md): Canonical registry storage, naming rules, export, restoration, and the public definition wrappers.
- [`definition-extension-guide.md`](./definition-extension-guide.md): Extension points and the storage rules custom definition nodes must follow to round-trip through the registry.

## Mapper

- [`mapper-runtime-guide.md`](./mapper-runtime-guide.md): Mapper runtime concepts, runtime registration, recursive conversion flow, and the canonical reference for field types, representations, codecs, and the `ConversionGateway`.
- [`recursive-mapping-behavior.md`](./recursive-mapping-behavior.md): Recursive branch traversal, resolver precedence, and how mixed source and target structures are mapped.

## Query

- [`3-query/query-model.md`](./3-query/query-model.md): Root query construction, field and relation references, selections, joins, and query inspection.
- [`3-query/expressions-and-conditions.md`](./3-query/expressions-and-conditions.md): Expressions, aliases, aggregates, semantic operations, and condition construction.
- [`3-query/grouping-ordering-pagination.md`](./3-query/grouping-ordering-pagination.md): Grouping, `HAVING`, sorting, and limit/offset pagination.
- [`3-query/bound-execution.md`](./3-query/bound-execution.md): Bound execution, detachment, and the neutral database facade.
- [`3-query/relation-loading.md`](./3-query/relation-loading.md): Structured relation selection, nested result shaping, loader lifecycle boundaries, and current restrictions.
