# Session save API

ON Data persists through **bind → synchronize → write**. `flush()` performs synchronization for staged writes and tracked objects; `sync()` lets callers synchronize and validate earlier without writing. The necessary write steps behind these calls are in [`../read-write.md`](../read-write.md).

```text
update / create / detach / remove   → register intent (and relation unlink)
sync($object)                       → adopt RepresentationState + push values into RecordState
sync()                              → apply pending intents + synchronize all tracked objects
flush()                             → apply pending intents + synchronize + write commands
```

Pending `update` / `create` intents live in `RepresentationIntentStore` until `sync($object)`, `sync()`, or `flush()` applies them. A root needs a supplied or tracked schema. A nested object can inherit its schema when a staged owning graph is attached, regardless of intent registration order. An unresolved intent raises `SyncException` before database writes; it is never silently ignored.

```php
$session->update($dto, $map);
$session->flush(); // no explicit sync required
```

Staging applies in memory before the flush transaction. If persistence fails, admitted writes remain tracked for retry; record history and tracked field baselines are restored together with record values. Explicit `sync()` does not write or commit.

## Shape: `RepresentationSchema`

```php
$map = $runtime->query($users)
    ->select($u->id, $u->name, $u->profile->name->as('profileName'))
    ->projection(); // compile only — no execute; root collection is on $map

$session->update($dto, $map);
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
| `update($obj, ?$schema)` | Existing row: adopt by key, then **PATCH** present DTO/map fields (dirty when non-key fields are present). Omitted properties are not writes; explicit `null` is a write. Missing related source records follow `skipWhenMissing`, not this PATCH rule. |
| `create($obj, ?$schema)` | New row |
| `identify($collection, $key)` | Existing row by key only — **no** field writes |
| Primary key on the projection / `->identity()` | Resolves **which** row for `update` — does not choose create vs update |

Explicit `update()` calls retain ordered PATCH semantics: when multiple staged DTOs patch the same field of the same row, the later patch wins, just as when each patch is explicitly synchronized before the next. This differs from independently editing two query-loaded writable objects: conflicting edits in a scalar sync batch raise `SyncException` instead of silently overwriting one another.

Optional root identity when the PK is not on the DTO:

```php
$session->update($dto, $map)->identity(['id' => 10]);
```

If both `identity()` and a readable PK on the DTO are present, they must agree.

Nested objects use the same verbs (lifecycle only until the owning graph is admitted by `sync` or `flush`):

```php
$session->update($dto, $map);
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
$session->update($dto, $map)
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

Owner must already be tracked (`sync` or `flush` the staged owner first).

## To-one clear

```php
$dto->profile = null;
$session->update($dto, $map);
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

## Writable query export

```php
$user = $q->to(stdClass::class)->writable($session)->fetchOne();
// or: $q->to(UserRow::class)->writable($session)->fetchOne();
$user->name = 'Ada';
$session->sync($user); // optional early synchronization; flush also synchronizes
$session->flush();
```

Pending `update`/`create` intents, including schema overlays on tracked objects, are applied by `flush()`. Keep `sync($object)` when early graph attachment or validation is useful. Merely assigning an untracked child to an already-tracked object does not stage a graph: call `update($owner)` or `sync($owner)` to walk that graph.
