# Phase 5 View Format

`Registry::all()` now includes a canonical `views` root:

```php
[
    'views' => [
        'user_summary' => [
            'class' => ON\Data\Definition\View\ViewDefinition::class,
            'name' => 'user_summary',
            'source' => 'users',
            'fields' => [
                'name' => [
                    'class' => ON\Data\Definition\View\ViewField::class,
                    'name' => 'name',
                    'type' => 'string',
                    'metadata' => [],
                ],
            ],
            'relations' => [
                'manager' => [
                    'class' => App\CustomViewRelation::class,
                    'name' => 'manager',
                    'collectionName' => 'users',
                    'inner_keys' => ['name'],
                    'outer_keys' => ['id'],
                    'metadata' => [],
                ],
            ],
            'metadata' => [
                'label' => 'User summary',
            ],
        ],
    ],
]
```

Rules:

1. `views` is always present after `Registry` normalization, even when the input omitted it.
2. View `source` stores only the source definition name.
3. `source` may refer to either a collection or another view because root definition names are globally unique.
4. View fields use the same nested storage style as collection fields, but default to `ViewField::class`.
5. View relations use the same nested storage style as collection relations and keep class discriminators for round-trip restoration.
6. Metadata remains plain data under `metadata`.
7. Runtime wrapper caches for views, fields, and relations never appear in `Registry::all()`.

Not implemented in Phase 5:

- computed field expressions;
- aggregates;
- query execution;
- writable views;
- cycle detection for view sources;
- persistence semantics for view fields.
