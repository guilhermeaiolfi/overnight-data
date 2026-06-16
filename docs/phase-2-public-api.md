# Public API Notes

## Phase 5 Delta

- `ON\Data\Definition\DefinitionInterface` was added.
- `ON\Data\Definition\Collection\CollectionInterface` now extends `DefinitionInterface`.
- `ON\Data\Definition\View\ViewDefinitionInterface` was added.
- `ON\Data\Definition\View\ViewDefinition` was added.
- `ON\Data\Definition\View\ViewField` was added.
- `ON\Data\Definition\Registry::view(string $name): ViewDefinitionInterface` was added.
- `ON\Data\Definition\Registry::getView(string|ViewDefinitionInterface $view): ?ViewDefinitionInterface` was added.
- `ON\Data\Definition\Registry::hasView(string $name): bool` was added.
- `ON\Data\Definition\Registry::getViews(): array<string, ViewDefinitionInterface>` was added.
- `ON\Data\Definition\Registry::getDefinition(string|DefinitionInterface $definition): ?DefinitionInterface` was added.
- `ON\Data\Definition\Registry::hasDefinition(string $name): bool` was added.
- `ON\Data\Definition\Field\FieldInterface::getParent(): DefinitionInterface` was added.
- `ON\Data\Definition\Field\FieldInterface::end()` now returns `DefinitionInterface`.
- `ON\Data\Definition\Relation\RelationInterface::getParent(): DefinitionInterface` was added.
- `ON\Data\Definition\Relation\RelationInterface::end()` now returns `DefinitionInterface`.
- `Collection::bindDefinitionArray()` was removed from the public API.
- Public hydration constructor parameters were removed from Collection, Field, Relation, Display, Interface, `M2MThrough`, `ViewDefinition`, and `ViewField`.

## Phase 4 Delta

- `Field::primaryKey()` and `FieldInterface::primaryKey()` were removed.
- `Collection::primaryKey(string ...$fieldNames): self` was added.
- `Collection::hasPrimaryKey(): bool` was added.
- `Collection::getPrimaryKey(): list<string>` now returns canonical ordered field names.
- `Collection::getPrimaryKeyFields(): list<FieldInterface>` now always returns an array.
- `Collection::getPrimaryKeyColumns(): list<string>` was added.
- `Collection::isCompositePrimaryKey(): bool` was added.
- `Collection::getKey(Key|array|string|int|float|bool): Key` was added.
- `Collection::getKeyFromRecord(array $record, bool $allowColumnNames = true): Key` was added.
- `ON\Data\Definition\Collection\PrimaryKeyDefinition` was removed.
- `ON\Data\Definition\Collection\PrimaryKeyValue` was removed.
- `ON\Data\Key` was added.
- `Field::isPrimaryKey()` remains, but it is now derived from collection metadata.

## Ongoing Notes

- Collection storage APIs such as `table()`, `database()`, `entity()`, `source()`, and primary-key methods remain collection-only.
- View definitions currently support structure only: name, source, fields, relations, metadata, registry round-trip, and fluent `end()`.
- View fields intentionally reuse the existing Field API surface, including storage-oriented setters, but those setters do not yet imply execution semantics for views.
