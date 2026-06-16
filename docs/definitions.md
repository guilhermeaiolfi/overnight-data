# Definitions

`ON\Data` stores definitions in one plain-data `Registry` master array.

## Core API

- `Registry` owns root collections and views.
- `Collection` and `ViewDefinition` own their fields, relations, and metadata.
- `Field`, relation, display, interface, and nested child nodes own their own defaults and nested arrays.
- `Key` models simple and composite collection identities.

## Names

Collection and view names share one namespace.

- Names must be strings.
- Whitespace-only names are rejected.
- Valid names are preserved exactly as given.

## Canonical arrays

Canonical arrays are created by the node class that owns them.

- `DefinitionNode::createDefinition()` merges explicit values over class defaults.
- Associative arrays merge recursively.
- Lists replace lists instead of appending.
- Public fluent creation writes complete canonical arrays immediately.

## Export and restoration

`Registry::all()` returns the canonical master array.

- Export validates plain data only.
- Read operations do not mutate stored definitions.
- Runtime wrapper caches are never exported.
- `new Registry($registry->all())` restores the same canonical structure.
- Restored arrays must already be canonical and include required class discriminators for stored nodes.

## Legacy caches

Legacy caches that relied on Registry normalization or field-level `pk` migration are no longer supported. Regenerate those caches from current fluent definitions before restoring them.
