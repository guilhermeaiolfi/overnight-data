# Extending Definitions

The supported extension model is class-based and round-trip oriented.

## Supported extension points

- custom `Collection` subclasses;
- custom `Field` subclasses;
- custom `ViewDefinition` subclasses;
- custom `ViewField` subclasses;
- custom relation subclasses;
- custom display subclasses;
- custom interface-definition subclasses.

## How extension works

Store the subclass as the definition `class` discriminator inside the Registry array. On restoration, `Registry` delegates reconstruction to the internal `DefinitionFactory`, which validates the discriminator before instantiating the wrapper.

Supported expectations:

- subclasses must preserve the parent contract of the base type they extend;
- exported definition state must remain plain data only;
- `all()` round-trip equality should hold across `new Registry($registry->all())`;
- custom relation subclasses may opt into view compatibility by accepting a `ViewDefinition` parent and avoiding collection-only assumptions.

## Plain-data rule

Definition exports may contain only:

- arrays;
- strings;
- ints;
- floats;
- bools;
- `null`.

Objects, closures, and resources are rejected by `Registry::all()`.

## Parent compatibility

- `Field` subclasses work under `Collection` and `ViewDefinition`.
- `ViewField` is the default field class for views.
- Collection-only relation subclasses should reject `ViewDefinition` parents explicitly with `InvalidRelationParentException`.

## Not public extension APIs

The following are implementation details, not supported application extension APIs:

- `DefinitionFactory`;
- `DefinitionNode` rebinding hooks;
- runtime wrapper caches.
