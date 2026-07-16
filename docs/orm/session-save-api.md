# Session save API

ON Data persists through **bind → sync → flush**:

```text
update / create / detach / remove   → register intent (and relation unlink)
sync($object)                       → adopt RepresentationState + push values into RecordState
flush()                             → write commands (also syncs already-tracked representations)
```

Pending `update` / `create` intents live in `RepresentationIntentStore` until `sync()`. Flush does **not** apply those intents; call `sync()` first after `update`/`create`.

## Shape: `RepresentationSchema`

```php
$map = $runtime->query($users)
    ->select($u->id, $u->name, $u->profile->name->as('profileName'))
    ->projection(); // compile only — no execute

$session->update($dto, $map)->from($users);
$session->sync($dto);
$session->flush();
```

Reuse a tracked object's schema:

```php
$session->update($inbound, $session->schemaOf($loaded));
$session->sync($inbound);
$session->flush();
```

## Lifecycle

| Call | Meaning |
|------|---------|
| `update($obj, ?$schema)` | Existing row: adopt by key, then **PATCH** present DTO/map fields (dirty when non-key fields are present) |
| `create($obj, ?$schema)` | New row |
| `identify($collection, $key)` | Existing row by key only — **no** field writes |
| Primary key on the projection / `->identity()` | Resolves **which** row for `update` — does not choose create vs update |

Optional root identity when the PK is not on the DTO:

```php
$session->update($dto, $map)->from($users)->identity(['id' => 10]);
```

If both `identity()` and a readable PK on the DTO are present, they must agree.

Nested objects use the same verbs (lifecycle only until parent `sync`):

```php
$session->update($dto, $map)->from($news);
foreach ($dto->images as $image) {
    $image->id ? $session->update($image) : $session->create($image);
}
$session->sync($dto);
$session->flush();
```

Attach an existing related row without writing its fields:

```php
$user->posts[] = $session->identify($posts, ['id' => 12]);
$session->sync($user);
```

## Flat related paths

```php
$session->update($dto, $map)->from($users)
    ->update('profile')                 // PK on map / DTO
    ->update('profile', ['id' => 5])    // explicit key
    ->create('posts');                  // NEW related + relation add
$session->sync($dto);
$session->flush();
```

## Detach vs remove

```php
$session->detach($post, $user, 'posts');           // unlink only
$session->detach(12, $user, 'posts');              // scalar single-field PK
$session->detach($session->identify($posts, ['id' => 12]), $user, 'posts');

$session->remove($post);                           // DELETE the post row
```

`detach` is a facade over `ToManyRelationState::remove` / to-one clear. Relation planners own through-row vs FK null behavior.

Owner must already be tracked (`sync` first).

## To-one clear

```php
$dto->profile = null;
$session->update($dto, $map)->from($users);
$session->sync($dto);
$session->flush();
```

## Exclusive HasMany omit-from-array

Only safe when the relation is **fully loaded**. Otherwise use `detach`.

## Identify

```php
$tag = $session->identify($tags, ['id' => 3]); // key-only, no query
$session->detach($tag, $user, 'tags');
$session->flush();
```

## Mutable query export

```php
$user = $q->to(stdClass::class)->mutable($session)->fetchOne();
$user->name = 'Ada';
$session->sync($user); // recommended; flush also syncs already-tracked objects
$session->flush();
```

Pending `update`/`create` intents (including schema overlays on a tracked object) are **not** applied by flush — call `sync` first.
