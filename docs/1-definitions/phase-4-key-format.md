# Phase 4 Key Format

## Collection storage

Collections store primary-key metadata only at collection level:

```php
[
    'collections' => [
        'users' => [
            'primaryKey' => ['id'],
        ],
        'post_user' => [
            'primaryKey' => ['post_id', 'user_id'],
        ],
    ],
]
```

Field-level `pk` flags are legacy data and are no longer migrated by `Registry`.

## Current guarantees

- `Collection::primaryKey()` preserves canonical field order.
- `Collection::getPrimaryKey()`, `getPrimaryKeyFields()`, and `getPrimaryKeyColumns()` preserve that order.
- `Key::getValues()` and `Key::getHash()` preserve that order.
- Key values remain limited to `string|int|float|bool`.

## Restoration note

If an old cache still relies on field-level `pk` flags, discard and regenerate that cache before restoring it into `Registry`.
