# Phase 5: Definition Field Resolver

This phase adds `ON\Data\Mapper\Resolver\DefinitionFieldResolver` to the default mapper runtime.

## What it does

- discovers one direct `DefinitionInterface` from `map()->args(...)`
- resolves exact effective field names with `DefinitionInterface::getField()`
- returns `FieldContext::fromField()` for matching fields
- enables scalar conversion for structurally untyped inputs such as arrays
- applies to top-level collection item mappings too

## Default resolver order

1. explicit fluent resolvers from `->resolver(...)`
2. `DefinitionFieldResolver`
3. `ReflectionPropertyFieldResolver`

This means definition metadata wins when both definition and reflection can resolve the same field, while reflection still handles fields absent from the definition.

## Canonical usage

```php
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Representation\StorageRepresentation;
use function ON\Data\Mapper\map;

$registry = new Registry();
$users = $registry->collection('users');
$users->field('id', 'int');
$users->field('active', 'bool');

$result = map([
    'id' => '1',
    'active' => '0',
])
    ->from(StorageRepresentation::class)
    ->args($users)
    ->to([]);
```

## Boundaries

- exact field-name lookup only
- no alias or column remapping
- no automatic `Registry` definition selection
- no nested mapping
- no persistence, query, or ORM behavior
