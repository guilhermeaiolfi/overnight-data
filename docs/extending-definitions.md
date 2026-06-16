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
- accept their logical parent in the public constructor;
- keep exported state as plain array data only;
- rebuild any nested wrapper caches from `afterBindDefinitionArray()` when they own nested children.

`DefinitionFactory` validates and binds stored wrappers, but it does not normalize or materialize missing defaults during restoration.

## Important boundary

Implementations that satisfy only `FieldInterface`, `RelationInterface`, or another public interface are not enough for Registry restoration by themselves. If the wrapper does not extend `DefinitionNode`, the Registry cannot store and restore it as canonical array-backed definition data.

## Plain-data rule

Definition exports may contain only arrays, strings, ints, floats, bools, and `null`. Objects, closures, and resources are rejected by `Registry::all()`.
