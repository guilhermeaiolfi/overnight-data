# Phase 4 Key Format

## Collection storage

Collections now store primary-key metadata only at collection level:

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

Field arrays do not retain a `pk` flag after `Registry` normalization.

## Canonical ordering

- Collection `primaryKey` order is canonical.
- `Collection::getPrimaryKey()`, `getPrimaryKeyFields()`, and `getPrimaryKeyColumns()` preserve that order.
- `Key::getValues()` and `Key::getHash()` also preserve that order.

## Accepted `getKey()` inputs

- simple scalar input for single-field keys;
- associative arrays keyed by canonical field names;
- associative arrays keyed by storage columns when unambiguous;
- positional lists whose order matches `Collection::getPrimaryKey()`;
- an existing `ON\Data\Key` from the same logical collection.

## Value restrictions

Primary-key values are currently limited to canonical scalar values:

- `string`
- `int`
- `float`
- `bool`

`null`, arrays, objects, and implicit type normalization are rejected in Phase 4.

## Equality

Two keys are equal when both of these match strictly:

- collection name;
- ordered named values.

Equivalent keys created before and after `Registry` round-trip restoration therefore compare equal.

## Hash format

- Prefix: `k1:`
- Payload: JSON object with `collection` and canonical `values`
- Encoding: base64url without padding

`__toString()` returns the hash, not a debug string.

## Debug format

`Key::getDebugString()` returns a human-readable diagnostic form:

- `users#id=10`
- `post_user#post_id=10,user_id=4`

This format is for logs and debugging only.

## JSON format

`jsonSerialize()` returns plain data only:

```php
[
    'collection' => 'post_user',
    'values' => [
        'post_id' => 10,
        'user_id' => 4,
    ],
]
```

## Registry round trip

Registry construction normalizes legacy field-level `pk` flags into collection-level `primaryKey` arrays. After construction, `Registry::all()` emits only the Phase 4 canonical format.

## Unsupported in Phase 4

- hash decoding;
- REST/URL identifier parsing;
- incomplete keys;
- FieldType-backed normalization;
- non-scalar component coercion.
