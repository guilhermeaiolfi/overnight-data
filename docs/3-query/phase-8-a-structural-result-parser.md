# Phase 8A Structural Result Parser

Phase 8A adopts Cycle ORM's structural parser subsystem into ON\Data so flat database rows can be folded into nested plain arrays before the existing mapper converts storage values into PHP representations.

## Why adopt it

Cycle already solved the parser problems that matter here:

- joined and separately loaded child rows
- duplicate folding caused by joins
- composite identities and composite references
- nested result trees
- reference-based mounting into previously parsed parents
- proxy and merge node behavior

Using that proven shape lets ON\Data gain a reliable parser without coupling it to ORM metadata, query execution, or value conversion.

## Pinned upstream source

- Project: Cycle ORM
- Commit: `a7a1db351df8037ff7a1196e19688bfc7d35c63e`

The ON\Data port keeps the parser structural only and deliberately removes parser-local typecasting.

## Structural scope

The parser:

- reads configured columns from flat rows
- deduplicates by configured identity fields
- builds nested singular and collection values
- indexes parsed parent records by reference fields
- mounts separately parsed child rows into those indexed parents
- merges structural inheritance-style rows when configured
- returns nested plain arrays in storage representation

The parser does not:

- know about definitions, collections, `ON\Data\Key`, relations, mappers, or query executors
- build SQL criteria or database parameters
- convert types, hydrate objects, or invoke mapping

## Renamed and removed responsibilities

Renamed upstream classes:

- `ArrayNode` -> `CollectionNode`
- `MultiKeyCollection` -> `ReferenceIndex`

Removed upstream responsibilities:

- parser-local typecasting classes
- database-aware `Parameter` generation
- ORM loader constants and runtime dependencies

## Joined and linked nodes

Joined children read from the same flat row as their parent:

```php
$root = new RootNode(
    columns: ['id', 'name'],
    identityFields: ['id'],
);

$root->joinNode(
    'posts',
    new CollectionNode(
        columns: ['id', 'user_id', 'title'],
        identityFields: ['id'],
        childFields: ['user_id'],
        parentFields: ['id'],
    ),
);
```

Given rows like:

```php
[
    [1, 'Ada', 10, 1, 'First post'],
    [1, 'Ada', 11, 1, 'Second post'],
]
```

the parser returns:

```php
[
    [
        'id' => 1,
        'name' => 'Ada',
        'posts' => [
            ['id' => 10, 'user_id' => 1, 'title' => 'First post'],
            ['id' => 11, 'user_id' => 1, 'title' => 'Second post'],
        ],
    ],
]
```

Linked children are parsed later through the same node tree. The child node asks its parent-owned `ReferenceIndex` for raw reference values, later parses separate rows, and mounts those rows directly into the already parsed parent records.

## Identity and reference behavior

- Identity fields are parser-local record identifiers, not collection primary-key objects.
- Composite identities preserve component order, boundaries, and scalar types.
- Composite references preserve parent-field order and child-field order.
- `ReferenceIndex` returns distinct raw reference values, not query criteria.
- Null identity values mean the current node row is absent.
- Null reference values skip indexing instead of creating invalid references.

## Duplicate folding

Joined Cartesian rows reuse one canonical in-memory record per identity. That allows nested descendants to mount into the already parsed record rather than duplicating parent arrays.

## Storage representation preservation

The parser preserves input values exactly as they were read from the database. Conversion stays in the existing ON\Data mapping layer, so the execution pipeline is:

```text
database rows
    -> structural result parser
    -> nested arrays in storage representation
    -> ON\Data mapper
    -> PHP representation, DTO, or object
```
