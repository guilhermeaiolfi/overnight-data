# Definition Extension Guide

`ON\Data` supports a class-based, array-backed extension model. Custom definition nodes participate in registry export and restoration only when they follow the same storage and ownership rules as the built-in nodes.

## Supported extension points

You can extend the definition layer with custom subclasses of:

- `Collection`
- `Field`
- `ViewDefinition`
- `ViewField`
- relation definitions
- display definitions
- interface-definition nodes
- nested child nodes owned by those custom definitions

## Required contract

Stored wrappers that must round-trip through `Registry` need to:

- extend `ON\Data\Support\DefinitionNode`
- implement the expected public interface
- define canonical defaults through `definitionDefaults()`
- inherit the built-in owner/name/array constructor contract
- avoid declaring a custom constructor
- keep exported state as plain array data only
- initialize runtime-only caches from `initializeRuntimeState()` when they own nested children

`DefinitionFactory` validates classes and constructs wrappers directly over final array slots. It does not normalize, rebind, or cache wrappers globally.

## Important boundary

Implementing only `FieldInterface`, `RelationInterface`, or another public interface is not enough for registry restoration. If a stored wrapper does not extend `DefinitionNode`, the registry cannot persist and restore it as canonical definition data.

## Plain-data rule

Definition exports may contain only arrays, strings, integers, floats, booleans, and `null`. Objects, closures, and resources are rejected by `Registry::all()`.

## Ownership rules

- Do not create orphan stored nodes and attach them later.
- Create custom nodes through the owning API and class-string extension points.
- Names come from owner map keys and are not mutable.
- Stored definition nodes cannot be cloned.
