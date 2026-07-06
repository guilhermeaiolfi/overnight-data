# Manual Mutable Projections

Manual mutable projections let an application attach write provenance to an object without executing a query. They declare representation property provenance over concrete `RecordState` and relation targets: `from()` establishes the root record identity, `properties()` declares public field paths, `as(...)` declares aliases, and relation targets declare relation item intent.

Use this when an object was created or extended by application code and a flat public property needs to write into a concrete record:

```php
$user->newPostTitle = 'New post';

$p = $session->projection($user);

$u = $p->from($users)->tracked();
$post = $p->create($u->posts);

$p
    ->properties(
        $post->title->as('newPostTitle'),
    )
    ->end();

$session->sync($user);
$session->flush();
```

Meaning:

```text
$user->newPostTitle -> NEW posts.title
$user.posts         -> add NEW posts record
```

## Core Model

SelectQuery projections get field targets from executed query provenance.

Manual projections get field targets from explicit property declarations plus manually supplied record identities. Manual projection is not compiling SQL result shape; it is declaring which public properties write to which tracked record fields.

The manual projection builder does not query. It normalizes manual property refs into `RepresentationFieldBinding` targets and registers relation intent through the existing `ToOneRelationState` / `ToManyRelationState` stores. Flush still uses the normal scalar sync, relation planners, command planning, and command execution pipeline.

## Root Source

Start with:

```php
$p = $session->projection($object);
```

Declare the root source with `from()`:

```php
$u = $p->from($users)->tracked();
$u = $p->from($users)->create(['id' => 10]);
$u = $p->from($users)->existing(['id' => 1], ['name' => 'Ada']);
```

`tracked()` reuses a concrete record already associated with the object for that collection. It throws when the object is not tracked, when no matching record exists, or when more than one matching record is ambiguous.

`create($values)` creates a new `RecordState`. Primary-key values inside `$values` are inserted as data; they do not turn the operation into an update or upsert.

`existing($key, $values)` creates or reuses a managed clean record for the collection/key. It does not query and does not imply upsert.

## Related Items

For a to-one relation:

```php
$profile = $p->create($u->profile);
$profile = $p->existing($u->profile, ['id' => 5]);
$profile = $p->tracked($u->profile, $profileObject);
```

For a to-many or many-to-many relation:

```php
$post = $p->create($u->posts);
$post = $p->existing($u->posts, ['id' => 'post-1']);
$post = $p->tracked($u->posts, $postObject);
```

The returned relation target exposes manual property refs:

```php
$post->title
$profile->name
```

For to-many and M2M relations, declaring properties through the relation path is not enough:

```php
$p->properties($u->posts->title->as('newPostTitle')); // throws
```

The developer must first create or identify one concrete item:

```php
$post = $p->create($u->posts);

$p->properties($post->title->as('newPostTitle'));
```

This keeps to-many item identity explicit.

## Properties

Manual projection `properties()` accepts writable manual property refs and aliases:

```php
$p
    ->properties(
        $u->name,
        $profile->name->as('profileName'),
        $post->title->as('newPostTitle'),
    )
    ->end();
```

Use `$target->all()` to expand every collection field on a target:

```php
$p->properties($u->all())->end();
```

Declared fields without aliases use their normal public path. Aliased fields write from the alias path. Missing manual public properties are ignored; present properties with `null` write `null`.

Manual projections do not accept query expressions, aggregates, subqueries, or arbitrary value expressions.

## Extending Query-Created Objects

Manual projections merge into existing query-created provenance:

```php
$user = $database->query($users)
    ->select($u->id, $u->name)
    ->to(stdClass::class)
    ->mutable($session)
    ->fetchOne();

$user->newPostTitle = 'New post';

$p = $session->projection($user);
$u = $p->from($users)->tracked();
$post = $p->create($u->posts);
$p->properties($post->title->as('newPostTitle'))->end();

$session->flush();
```

The existing `id` and `name` provenance stays intact, and the manual projection adds `newPostTitle` plus relation intent.

## Boundaries

Manual projections do not introduce a second field-target DSL, class-to-binding inference, upsert, lazy loading, repositories, proxies, or SQL changes. They are a manual identity provider for the same representation binding and persistence pipeline used by mutable query projections.

Manual projection does not use `SelectQuery`, `SelectQueryBindingCompiler`, or query selection normalization. Query mutable export still compiles through `SelectQueryBindingCompiler`.
