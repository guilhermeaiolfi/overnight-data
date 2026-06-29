# Recursive Mapping Behavior

`ON\Data\Mapper` maps one branch level at a time and recurses only when a child resolves as a branch.

## Runtime model

- A concrete mapper decides whether it can read the current source value and how to enumerate that value's immediate children.
- A writer decides how the target branch is created and how resolved child values are written into it.
- Recursive dispatch flows through `MapperManager::mapNode()`, so each branch can be mapped by a different mapper than its parent.

This allows mixed trees such as array root -> object child -> array grandchild without a separate planning layer.

## Resolver precedence

Default resolution order is:

1. A custom resolver supplied through `->resolver(...)`
2. `FieldMapNodeResolver`
3. `DefinitionNodeResolver`
4. `ReflectionPropertyNodeResolver`
5. `GenericNodeResolver`
6. `PassthroughNodeResolver`

`FieldMap` therefore overrides definition and reflection metadata for the paths it defines, while passthrough remains the last fallback.

## Definition-aware recursion

When one `DefinitionInterface` is supplied through `->args($definition)`, the runtime can resolve:

- scalar field metadata for conversion;
- relation branches for nested traversal.

Definition metadata describes structure, but runtime collection behavior still comes from the actual source value, relation cardinality, PHPDoc-supported list metadata, or explicit collection mapping at the root.

## Branch behavior

- Recursive mapping reuses registered mappers and writers instead of constructing a separate per-type pipeline.
- Dotted input keys can be expanded before traversal so flat payloads can map into nested arrays or objects.
- Cycle checks are applied at recursive dispatch boundaries.
- Field conversion still routes through the `ConversionGateway`, even when the branch structure changes recursively.

## Current limits

- No constructor hydration.
- No readonly-target hydration.
- PHPDoc list inference is intentionally limited to the forms covered by the current tests.
