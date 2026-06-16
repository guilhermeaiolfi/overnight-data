# Release 0.1 Checklist

## Definition architecture

- `Registry` is limited to root collections/views, name validation, runtime caches, root lookup, and plain-data export.
- `DefinitionNode` owns canonical default creation, immutable contextual names, and the inherited constructor contract.
- `DefinitionFactory` validates and constructs stored wrappers without normalization or cache ownership.
- Fields, relations, displays, interfaces, and custom nested children are owned by their parent node classes.
- No orphan-node registration, attach APIs, or clone-based detachment remain.
- Read-only access does not mutate stored definition arrays.
- Legacy caches that depended on Registry normalization are documented for regeneration.

## Quality gates

- `composer validate --strict`
- `composer install`
- `composer dump-autoload`
- `composer test`
- `composer analyse`
- `composer check-style`
- `vendor/bin/phpstan analyse --configuration phpstan.neon.dist --level=2`
