# Phase 3 Storage Format

`Registry::all()` now returns one plain master array with this implemented shape:

```php
[
    'collections' => [
        'users' => [
            'class' => 'App\\CustomCollection',
            'name' => 'users',
            'table' => 'users',
            'database' => 'default',
            'entity' => stdClass::class,
            'parentCollection' => null,
            'scope' => null,
            'repository' => null,
            'mapper' => null,
            'source' => null,
            'note' => null,
            'description' => null,
            'hidden' => false,
            'fileLocation' => '/path/to/file.php',
            'metadata' => [
                'domain' => 'accounts',
            ],
            'fields' => [
                'id' => [
                    'class' => 'App\\CustomField',
                    'name' => 'id',
                    'column' => 'user_id',
                    'type' => 'int',
                    'alias' => null,
                    'required' => true,
                    'searchable' => null,
                    'sensible' => false,
                    'default' => null,
                    'castDefault' => false,
                    'generatedFromRelation' => null,
                    'validation' => null,
                    'validationMessages' => [],
                    'description' => null,
                    'typecast' => null,
                    'metadata' => [],
                    'nullable' => false,
                    'hidden' => false,
                    'unique' => false,
                    'indexed' => false,
                    'max_length' => 255,
                    'numeric_precision' => 2,
                    'default_value' => null,
                    'data_type' => null,
                    'comment' => null,
                    'pk' => true,
                    'auto_increment' => false,
                    'filterable' => false,
                    'display' => [
                        'class' => 'App\\CustomDisplay',
                        'type' => 'badge',
                    ],
                    'interface' => [
                        'class' => 'App\\CustomInterface',
                        'limit' => 32,
                    ],
                ],
            ],
            'relations' => [
                'manager' => [
                    'class' => 'App\\CustomRelation',
                    'nullable' => false,
                    'cascade' => true,
                    'load' => 'lazy',
                    'inner_keys' => ['id'],
                    'outer_keys' => ['id'],
                    'collectionName' => 'users',
                    'name' => 'manager',
                    'where' => [],
                    'orderBy' => [],
                    'loader' => null,
                    'metadata' => [],
                    'display' => [
                        'class' => 'App\\CustomDisplay',
                        'type' => 'relation',
                    ],
                    'interface' => [
                        'class' => 'App\\CustomInterface',
                        'limit' => 12,
                    ],
                ],
            ],
        ],
    ],
]
```

Implemented rules:

1. Root keys:
   - Only `collections` is created by default.
2. Collection keys:
   - Collection defaults are materialized when the collection entry is created.
   - `fields`, `relations`, and `metadata` are always arrays after normalization.
3. Field keys:
   - Basic field metadata and schema metadata are stored directly on the field array.
   - Nested display and interface definitions are stored under `display` and `interface`.
4. Relation keys:
   - Base relation metadata is stored directly on the relation array.
   - `M2MRelation` adds `collection_factory` and nested `through` data.
5. Class discriminator behavior:
   - `class` is used for collection, field, relation, display, and interface restoration.
   - Missing collection/field classes are normalized to the default concrete class.
   - Missing relation class restoration is rejected.
6. Metadata format:
   - Collection, field, and relation metadata stays under a plain nested `metadata` array.
7. Restoration rules:
   - `Registry`, `Collection`, `Field`, `Relation`, `Display`, `Interface`, and `M2MThrough` wrappers bind to the nested arrays by reference.
   - Wrapper caches are runtime-only and do not appear in `all()`.
