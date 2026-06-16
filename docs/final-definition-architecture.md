# Final Definition Architecture

`ON\Data` now uses one final ownership model for stored definitions.

## Ownership

- `Registry` owns collections and views.
- Collections and views own fields and relations.
- Fields and relations own display and interface children.
- `M2MRelation` owns its `through` child.
- Custom nodes may own additional nested children without Registry or factory special cases.

Stored nodes are never created as orphan objects and attached later.

## Names

- Names are immutable runtime context.
- The owner map key is the node name.
- Canonical stored arrays do not repeat collection, view, field, or relation names inside the node body.

## Construction

- The owner creates the final array slot first.
- `DefinitionNode::createDefinition()` materializes canonical plain data for the selected class.
- `DefinitionFactory` validates the class and constructs the wrapper directly over the final slot.
- No construct-then-rebind lifecycle remains.

## Caching

- `Registry` caches collection and view wrappers locally.
- `FieldMap` caches field wrappers locally.
- `RelationMap` caches relation wrappers locally.
- Display, interface, and through wrappers are cached by their owning node.

These are owner-local runtime caches, not a global identity map.

## Extension contract

- Extend the appropriate `DefinitionNode`-based class.
- Do not declare a custom constructor.
- Supply stored defaults through `definitionDefaults()`.
- Keep stored state as plain data only.
- Initialize runtime-only caches through `initializeRuntimeState()` when needed.

## Boundaries

- `Registry::all()` validates plain data and exports the master array unchanged.
- Reads do not mutate stored arrays.
- Stored definition nodes cannot be cloned.
- Legacy caches that relied on normalization or field-level `pk` migration must be regenerated.
