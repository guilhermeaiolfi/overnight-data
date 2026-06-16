# Definitions

`ON\Data` stores definitions in one plain-data `Registry` master array.

## Core API

- `Registry` owns root collections and views.
- `Collection` and `ViewDefinition` own their fields, relations, and metadata.
- `Field`, relation, display, interface, and nested child nodes own their own defaults and nested arrays.
- Nodes are created only through their owner APIs such as `Registry::collection()`, `DefinitionInterface::field()`, and `DefinitionInterface::relation()`.
- `Key` models simple and composite collection identities.

## Names

Collection and view names share one namespace.

- Names must be strings.
- Whitespace-only names are rejected.
- Valid names are preserved exactly as given.
- Names are immutable runtime context supplied by the owner map key.
- Canonical stored arrays do not contain redundant `name` entries for stored nodes.

## Canonical arrays

Canonical arrays are created by the node class that owns them.

- `DefinitionNode::createDefinition()` merges explicit values over class defaults.
- Associative arrays merge recursively.
- Lists replace lists instead of appending.
- Public fluent creation writes complete canonical arrays immediately.
- The owner creates the final array slot before the wrapper exists.
- Wrappers bind directly to that final slot during construction.

## Export and restoration

`Registry::all()` returns the canonical master array.

- Export validates plain data only.
- Read operations do not mutate stored definitions.
- Runtime wrapper caches are never exported.
- `new Registry($registry->all())` restores the same canonical structure.
- Restored arrays must already be canonical and include required class discriminators for stored nodes.
- `Registry`, field maps, relation maps, and single-child owners cache wrappers locally for repeated lookups.
- Repeated lookups within one owner return the same wrapper instance.

## Legacy caches

Legacy caches that relied on Registry normalization or field-level `pk` migration are no longer supported. Regenerate those caches from current fluent definitions before restoring them.
