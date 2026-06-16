# Phase 1 Notes

1. PHP version copied from Overnight: `>=8.1` from the `cycle-mutation` branch `composer.json`.
2. Tooling copied from Overnight: PHPUnit configuration shape from `phpunit.xml`; coding style from `.php-cs-fixer.php`; PHPUnit version `^11.5` and php-cs-fixer version `^3.64` from `composer.json`.
3. Original copied support implementations: `src/Config/Config.php` and `src/Config/Dot.php` in the Overnight `cycle-mutation` branch.
4. Behavior changed to remove Overnight dependencies:
   - `Laminas\Stdlib\ArrayUtils::merge()` was replaced with a small internal recursive merge helper in `ON\Data\Support\DefinitionNode`.
   - The original `Config`-style behavior was inlined into `ON\Data\Support\DefinitionNode` instead of keeping a standalone `Config` class.
   - Only the minimal `Dot` and `DefinitionNode` behaviors needed for this package were extracted; the wider Overnight config package was not migrated.
5. Reference-binding mechanism selected: `ON\Data\Support\DefinitionNode::fromReference(array &$items)` creates an instance and calls the protected `bind(array &$items)` method, which delegates to `Dot::setReference(array &$items)`.
6. Concern to revisit during Registry migration: once wrapper classes bind to nested registry arrays, we should confirm whether child defaults should be applied before or after binding so default materialization never overwrites existing registry state.
7. Difference between the actual Overnight source and this specification:
   - The checked-in Overnight branch includes PHPUnit and php-cs-fixer configuration, but no committed PHPStan configuration. A minimal `phpstan.neon.dist` and `phpstan/phpstan` dev dependency were added here to satisfy the Phase 1 static-analysis requirement while staying compatible with the same PHP baseline.
