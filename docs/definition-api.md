# Definition API

`ON\Data` stores collection and view metadata in a single canonical `Registry` array. The public definition API is built around array-backed wrappers that read and write directly against that shared structure.

## Overview

- `Registry` owns the root collection and view definitions.
- `Collection` and `ViewDefinition` own fields, relations, and definition metadata.
- Child nodes such as fields, relations, displays, interfaces, and nested definition objects own their own defaults and nested arrays.
- Definitions are created through owner APIs such as `Registry::collection()`, `DefinitionInterface::field()`, and `DefinitionInterface::relation()`.
- `Key` represents simple and composite collection identities.

## Naming model

Collection names and view names share one namespace within a registry.
Field names and relation names share one member namespace within each collection or view.

- Names must be strings.
- Whitespace-only names are rejected.
- Valid names are preserved exactly as provided.
- Names are runtime context supplied by the owner map key and are not stored redundantly inside canonical child arrays.
- A definition cannot contain both `field('posts')` and `relation('posts', ...)`.
- This keeps future member access unambiguous, for example `$users->name` and `$users->posts`.

```php
$users->field('posts');
$users->relation('posts', ...); // invalid
```

## Canonical storage model

Canonical arrays are produced by the node class that owns them.

- `DefinitionNode::createDefinition()` merges explicit values over class defaults.
- Associative arrays merge recursively.
- List values replace existing lists instead of being appended.
- Fluent creation writes the complete canonical structure immediately.
- The owning node creates the final array slot before the wrapper instance exists.
- Wrapper instances bind directly to that final slot during construction.

## Export and restoration

`Registry::all()` returns the canonical master array.

- Export validates that stored state contains plain data only.
- Read operations do not mutate stored definitions.
- Runtime wrapper caches are never exported.
- `new Registry($registry->all())` restores the same canonical structure.
- Restored arrays must already be canonical and include any required class discriminators for custom stored nodes.
- `Registry`, field maps, relation maps, and single-child owners cache wrapper instances locally for repeated lookups.
- Repeated lookups within the same owner return the same wrapper instance.

## Compatibility note

Legacy caches that depended on registry-side normalization or field-level `pk` migration are no longer supported. Regenerate those caches from current fluent definitions before restoring them.
