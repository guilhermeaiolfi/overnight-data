# Phase 6: Relation References

This phase adds query-owned relation paths without adding joins or relation loading.

## Property access

Root query members now resolve through the definition member namespace:

```php
$posts = $users->posts;
$authorName = $users->posts->author->name;
```

- `$users->name` returns a root `FieldRef`
- `$users->posts` returns a root `RelationRef`
- `$users->posts->title` returns a related `FieldRef`

Explicit APIs remain available:

```php
$users->field('name');
$users->relation('posts');
```

Property access and explicit access share owner-local caches.

## Paths and caching

Each query owns cached root field and relation references.
Each `RelationRef` owns cached related fields and nested relations for its own path only.

```php
$users->posts === $users->relation('posts');
$users->posts->author === $users->posts->relation('author');
```

Paths are inspectable:

```php
$users->posts->getPath();              // ['posts']
$users->posts->author->getPath();      // ['posts', 'author']
$users->posts->author->name->getPath();// ['posts', 'author', 'name']
```

Distinct paths stay distinct even when they target the same collection:

```php
$orders->billingAddress->city;
$orders->shippingAddress->city;
```

Those references are different objects with different paths.

Self-relations are constructed lazily, so finite traversals are safe:

```php
$employees->manager->manager->name;
```

## Current execution boundary

`RelationRef` is a query-model object only in this phase.
It is not selectable and does not carry join metadata, aliases, predicates, or loading behavior.

Related `FieldRef` objects remain valid query-model expressions, but Cycle execution rejects them explicitly until relation joins are implemented.

```php
$users->select($users->posts->title); // unsupported for now
```

This prevents related fields from being rendered against the root table alias before join support exists.
