# Extending Definitions

The supported extension model is class-based and array-backed.

## Supported extension points

- custom `Collection` subclasses;
- custom `Field` subclasses;
- custom `ViewDefinition` subclasses;
- custom `ViewField` subclasses;
- custom relation subclasses;
- custom display subclasses;
- custom interface-definition subclasses;
- custom nested child nodes owned by those subclasses.

## Required contract

Stored wrappers that must round-trip through `Registry` need to:

- extend `ON\Data\Support\DefinitionNode`;
- implement the expected public interface;
- define canonical defaults through `definitionDefaults()`;
- inherit the built-in owner/name/array constructor contract;
- avoid declaring a custom constructor;
- keep exported state as plain array data only;
- initialize runtime-only caches from `initializeRuntimeState()` when they own nested children.

`DefinitionFactory` validates classes and constructs wrappers directly over final array slots. It does not normalize, rebind, or cache wrappers globally.

## Important boundary

Implementations that satisfy only `FieldInterface`, `RelationInterface`, or another public interface are not enough for Registry restoration by themselves. If the wrapper does not extend `DefinitionNode`, the Registry cannot store and restore it as canonical array-backed definition data.

## Plain-data rule

Definition exports may contain only arrays, strings, ints, floats, bools, and `null`. Objects, closures, and resources are rejected by `Registry::all()`.

## Ownership rules

- Do not create orphan stored nodes and attach them later.
- Create custom nodes through the owning API and class-string extension points.
- Names come from owner map keys and are not mutable.
- Stored definition nodes cannot be cloned.
