# Registry Storage Format

Phase 5 extends the Phase 3 master-array model with a `views` root while preserving the existing collection shape.

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
            'primaryKey' => [
                'id',
            ],
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
    'views' => [
        'user_summary' => [
            'class' => 'App\\CustomViewDefinition',
            'name' => 'user_summary',
            'source' => 'users',
            'metadata' => [
                'label' => 'User summary',
            ],
            'fields' => [
                'name' => [
                    'class' => 'App\\CustomViewField',
                    'name' => 'name',
                    'type' => 'string',
                    'metadata' => [],
                ],
            ],
            'relations' => [
                'manager' => [
                    'class' => 'App\\CustomViewRelation',
                    'collectionName' => 'users',
                    'name' => 'manager',
                    'inner_keys' => ['name'],
                    'outer_keys' => ['id'],
                    'metadata' => [],
                ],
            ],
        ],
    ],
]
```

Implemented rules:

1. Root keys:
   - `collections` and `views` are always present after normalization.
2. Collection keys:
   - Collection defaults are materialized when the collection entry is created.
   - `primaryKey` is always stored at collection level as an ordered list of field names.
   - `fields`, `relations`, and `metadata` are always arrays after normalization.
3. View keys:
   - Views store `class`, `name`, `source`, `fields`, `relations`, and `metadata`.
   - `source` stores only the source definition name and may point to either a collection or another view.
   - Collections and views share one definition-name namespace, so duplicate root names are rejected.
4. Field keys:
   - Basic field metadata and schema metadata are stored directly on the field array.
   - Field arrays do not store independent primary-key flags after Registry normalization.
   - Nested display and interface definitions are stored under `display` and `interface`.
5. Relation keys:
   - Base relation metadata is stored directly on the relation array.
   - `M2MRelation` adds `collection_factory` and nested `through` data.
6. Class discriminator behavior:
   - `class` is used for collection, view, field, relation, display, and interface restoration.
   - Missing collection, view, and field classes are normalized to their default concrete classes.
   - Missing relation class restoration is rejected.
7. Metadata format:
   - Collection, view, field, and relation metadata stays under a plain nested `metadata` array.
8. Restoration rules:
   - `Registry`, `Collection`, `ViewDefinition`, `Field`, `ViewField`, `Relation`, `Display`, `Interface`, and `M2MThrough` wrappers bind to the nested arrays by reference.
   - Wrapper caches are runtime-only and do not appear in `all()`.
