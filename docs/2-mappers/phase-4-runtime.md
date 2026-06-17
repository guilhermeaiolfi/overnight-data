# Mapper Phase 4 Runtime

Phase 4 replaces pair-specific structural mappers with a composable runtime built from walkers, writers, and resolver chains.

## Runtime shape

```text
Input
  -> Walker
  -> Resolver chain
  -> Representation conversion
  -> Writer
  -> Output
```

The runtime is input-driven:

- one walker enumerates the current source
- resolvers inspect walker evidence and prepared-target evidence
- conversion is centralized in `FieldConversionCoordinator`
- one writer performs final target-slot assignment

## Built-in components

Registered by default in this order:

```text
Walkers:
  ArrayWalker
  ObjectWalker

Writers:
  ArrayWriter
  ObjectWriter

Resolvers:
  ReflectionPropertyFieldResolver
```

This replaces the old pair-specific classes and enables shallow combinations such as:

- array -> array
- array -> `stdClass`
- array -> DTO
- `stdClass` -> array
- `stdClass` -> `stdClass`
- `stdClass` -> DTO
- DTO -> array
- DTO -> `stdClass`
- DTO -> DTO

## Fluent overrides

One mapping can override any structural side without global registration:

```php
$result = map($source)
    ->walker(CustomWalker::class)
    ->resolver(CustomResolver::class)
    ->writer(CustomWriter::class)
    ->args($customEvidence)
    ->to([]);
```

Resolver order is:

```text
explicit resolver() calls
registered default resolvers
```

## Array API

`to([])` is now the canonical array destination.

Removed APIs:

- `using()`
- `toArray()`

## Scope limits

Still intentionally shallow:

- no nested graphs
- no recursive class-property mapping
- no typed nested lists
- no definition-row mapping
- no ORM or framework integration
