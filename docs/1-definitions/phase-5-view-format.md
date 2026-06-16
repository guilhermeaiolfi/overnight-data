# Phase 5 View Format

`Registry::all()` includes a canonical `views` root.

## Current rules

1. View arrays are created through `ViewDefinition` defaults.
2. `source` stores only the source definition name.
3. View fields and relations are stored using the same array-backed node rules as collections.
4. View field and relation names are stored as map keys rather than redundant nested `name` values.
5. Runtime wrapper caches are never exported.
6. Restoring and reading a view does not backfill missing defaults into the stored array.
7. Invalid nested field, relation, display, interface, or custom child classes fail when that child is accessed, not because Registry eagerly walks the whole tree.

`ViewDefinition` remains structural only. Expressions, aggregates, query execution, and writable-view behavior are still out of scope.
