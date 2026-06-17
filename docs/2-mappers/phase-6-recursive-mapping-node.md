# Phase 6: Recursive MappingNode Traversal

This phase replaces the visitor-callback mapper flow with a recursive `MappingNode` pipeline owned by walkers.

## What changed

- `MappingNode` is now the unit of current-node work.
- `MappingContext` now holds effective configuration for the active mapping frame only.
- walkers own traversal, collection iteration, and recursive dispatch
- `MapperManager` now selects walkers and writers and constructs mapping-local resolver chains
- writers only prepare, write one completed value, and finish
- field resolvers now resolve leaf `FieldContext` objects from `MappingNode`
- nested recursion is derived directly into another `MappingNode` rather than a temporary nested-mapping carrier

Recursive traversal decisions are owned by walkers rather than a separate per-node resolver chain.

## Supported nested behavior

- array, `stdClass`, and DTO recursion in both directions
- typed DTO properties
- PHPDoc list forms `Type[]`, `list<Type>`, and `array<Type>`
- nested `MapFrom`, `MapTo`, and `Hidden`
- nested definition relation scope replacement
- nested representation conversion
- top-level collection items containing nested structures
- object cycle detection within the current ancestor chain, including collection-item recursion
- destination reflection metadata wins for typed object targets
- source reflection remains available for untyped outbound targets
- scalar relation identifiers do not trigger recursion
- compatible typed-collection instances preserve identity

## Dotted-key expansion

`ArrayWalker` now expands dotted source keys by default before creating nodes:

```php
map([
    'author.id' => '2',
    'author.active' => '0',
])
    ->from(StorageRepresentation::class)
    ->args($posts)
    ->to([]);
```

Result:

```php
[
    'author' => [
        'id' => 2,
        'active' => false,
    ],
]
```

Disable this with `new ArrayWalkerOptions(false)` when literal dotted keys are required.

## Definition scope

When a nested node matches a relation on the active `DefinitionInterface`, the child mapping receives the relation target definition instead of the parent definition. Unrelated arguments are preserved.

Relation metadata only triggers recursion for structural values. Scalar relation payloads such as `2`, `'2'`, or `null` continue through field resolution, conversion, and final assignment without nested walker selection.

## Extension point

Custom traversal behavior belongs in a custom walker. `MappingNode` remains the emitted runtime node, while field resolvers stay focused on leaf conversion metadata.

Final ownership model:

```text
MappingContext = effective configuration for the active mapping frame
MappingNode    = current traversal value, target, ancestry, and reflection evidence
FieldContext   = minimal scalar-conversion facts
Walker         = recursive structural decisions
Writer         = target assignment
```

`MappingNode` derives path, parent source, parent target, and ancestor-chain cycle inspection from its own tree. Arguments and collection mode are read through the node API, but stored only in `MappingContext`.

Cycle validation now occurs at `MapperManager::mapNode()` before walker selection, so every recursive dispatch uses the same ancestor-chain guard.

Parent target semantics remain intentionally asymmetric:

- object parents are observed as the same live mutated object instance
- array parents are prepared-target snapshots
