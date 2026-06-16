# Registry Storage Format

`Registry::all()` returns one canonical plain-data master array with `collections` and `views` roots.

## Current rules

1. Root arrays:
   - `collections` and `views` are Registry-owned roots.
   - Registry validates root names, root class discriminators, and collection/view name conflicts.
2. Node defaults:
   - Each stored node class owns its own defaults through `definitionDefaults()`.
   - Canonical arrays are created through `DefinitionNode::createDefinition()`.
3. Restoration:
   - Restored arrays are treated as already canonical.
   - Registry does not recursively normalize nested nodes.
   - Nested class discriminators are validated lazily when the owning wrapper hydrates them.
4. Ownership:
   - no stored node is created as an orphan and attached later;
   - collections and views own fields, relations, and metadata;
   - fields and relations own their display/interface children;
   - `M2MRelation` owns its `through` child;
   - custom subclasses may own additional nested children without Registry changes.
5. Read-only behavior:
   - wrapper caches are runtime-only;
   - reads do not rewrite stored arrays;
   - `Registry::all()` validates plain data but does not normalize.
6. Names:
   - collection, view, field, and relation names are stored only as owner-map keys;
   - canonical stored node arrays do not repeat those names inside the node body.
