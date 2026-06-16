# Definitions

`ON\Data` currently provides a structural definition system backed by one plain-data `Registry` array.

## Core API

- `Registry` owns collections and views.
- `Collection` defines persisted model structure plus collection-only storage metadata.
- `ViewDefinition` defines structural business-model views.
- `Field` and `ViewField` define per-definition fields.
- Relation classes define structural links between definitions and collections.
- `Key` models simple and composite collection identities.

## Fluent usage

```php
$registry
    ->collection('users')
        ->primaryKey('id')
        ->field('id', 'integer')
            ->end()
        ->end();
```

```php
$registry
    ->view('user_summary')
        ->source('users')
        ->field('id', 'integer')
            ->end()
        ->end();
```

`end()` returns the parent wrapper:

- field and relation wrappers return their owning `Collection` or `ViewDefinition`;
- collection and view wrappers return the owning `Registry`.

## Names

Collection and view names share one namespace.

- Empty and whitespace-only names are rejected.
- Non-empty trimmed names are allowed.
- Dots, dashes, spaces, and namespace-like separators are treated as literal keys.

## Collections

Collections support:

- `field()`, `getField()`, `getFields()`;
- `relation()`, plus convenience helpers such as `hasOne()`, `hasMany()`, and `belongsTo()`;
- `metadata()`;
- collection-only storage metadata such as `table()`, `database()`, `entity()`, `source()`;
- `primaryKey()` plus `getKey()` and `getKeyFromRecord()`.

Primary keys are stored only at collection level in canonical field order.

## Views

Views currently support:

- `source(string|DefinitionInterface)`;
- structural fields and relations;
- metadata;
- Registry round trip.

`ViewDefinition` is structural only. Field source expressions, aggregates, cardinality, query execution, and writable-view semantics are not implemented yet.

## Export and restoration

`Registry::all()` returns the canonical master array.

- The array is the sole source of truth.
- Runtime wrapper caches are never exported.
- Exported data must stay plain data only.
- `new Registry($registry->all())` restores the same canonical structure.

## Key

`Key` supports:

- simple and composite identities;
- scalar, associative, and positional input forms where supported;
- deterministic equality and hashing;
- JSON serialization as plain data.
