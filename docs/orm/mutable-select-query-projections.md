# Mutable SelectQuery Projections

Mutable `SelectQuery` export is more than a convenient object shape. When data comes from a mutable query export, the executed query itself is the field-target declaration. For objects that did not come from a query, use manual mutable projections with `Session::projection($object)`.

This page explains how query selection provenance and hidden internal identity selections let ON Data route public object writes to the correct `RecordState` fields — including flattened related fields, nested relation items, and relation intent created after the query runs.

See also:

- [`../query/query-model.md`](../query/query-model.md) — query construction, selections, aliases, and result export
- [`../query/bound-execution.md`](../query/bound-execution.md) — bound execution, result modes, and the data runtime
- [`representation-schema.md`](./representation-schema.md) — recursive `RepresentationSchema` model and flat projection adoption
- [`persistence.md`](./persistence.md) — scalar sync, relation sync, command planning, and flush orchestration
- [`manual-mutable-projections.md`](./manual-mutable-projections.md) — non-executing manual projection declarations

## Core concept

A mutable query projection is not just an object export. It carries provenance:

```text
public object field
  -> selected field/expression provenance
  -> concrete RecordState
  -> writable field target
```

For example:

```text
$user->profileName
  -> profiles#5.name
```

The public object does not need to expose `profile_id`. The query/runtime may include hidden internal identity selections so the adopter can track the concrete record behind `profileName`. Selections tagged `SelectionTag::INTERNAL` are stripped from public array and object results, but they are required for mutable flat projection tracking.

When a mutable projection is created by `SelectQuery`, the query is the field-target declaration.

Mutable export requirements:

- `to(stdClass::class)` is required
- an explicit `Session` is required
- schema and provenance are compiled only for mutable export
- one schema is compiled per fetch operation and reused across rows
- each object still gets its own `RepresentationState`

## Flattened ToOne update

A user object can expose a related profile field flattened directly onto it:

```php
use ON\Data\ORM\Session;
use stdClass;

$session = new Session($executor);

$u = $runtime->query($users);

$user = $u
    ->select(
        $u->id,
        $u->name,
        $u->profile->name->as('profileName'),
    )
    ->to(stdClass::class)
    ->mutable($session)
    ->fetchOne();

$user->profileName = 'Updated public profile';

$session->sync($user);
$session->flush();
```

Intended internal meaning:

```text
$user->id
  -> users#1.id

$user->name
  -> users#1.name

$user->profileName
  -> profiles#5.name
```

`flush()` updates `profiles.name`, not `users.name`.

The query/projection adopter must have enough hidden identity information to resolve `profiles#5`. For a flattened related field, the compiler adds internal identity selections for the related record. If that identity is missing from the executed query result, projection adoption fails rather than guessing the target row.

## Existing relation item from query

An existing related object can come from another mutable query, then be added to a tracked relation.

Assume `$user` was already loaded from a mutable query with a `posts` relation schema:

```php
$p = $runtime->query($posts);

$post = $p
    ->select($p->id, $p->title)
    ->where(x()->eq($p->id, 'post-1'))
    ->to(stdClass::class)
    ->mutable($session)
    ->fetchOne();

$user->posts[] = $post;

$session->sync($user);
$session->flush();
```

Explanation:

```text
$post is already tracked as an existing record because it came from a mutable query.
$user->posts[] creates relation intent when $user is synced.
```

The post's scalar field provenance comes from its own query projection. Adding it to `$user->posts` creates relation intent on the already-tracked user. `sync($user)` admits the changed graph; `flush()` can then update post scalars if they changed and persist the relation link as needed.

## New relation item added to queried object

A queried/tracked user can receive a new child object through its relation schema:

```php
$u = $runtime->query($users);

$user = $u
    ->select($u->id, $u->name)
    ->posts
    ->fields('id', 'title')
    ->to(stdClass::class)
    ->mutable($session)
    ->fetchOne();

$post = new stdClass();
$post->id = 'post-1';
$post->title = 'First post';

$user->posts[] = $post;

$session->sync($user);
$session->flush();
```

Explanation:

```text
The user is tracked.
The posts relation schema gives the context for the new object.
The new object is discovered by sync().
After b45374f, discovered untracked related objects default to NEW even with an application-assigned primary key.
```

Primary-key presence is data, not lifecycle intent. A newly attached plain object discovered through an explicit relation schema is adopted as `NEW`, even when it already contains a readable primary-key value. Duplicate application-assigned keys are planned as inserts and should fail at the database constraint level rather than being silently converted to updates.

## Existing key-only relation item

Use `identify()` when the developer only wants to refer to an existing row by key:

```php
$user->posts[] = $session->identify($posts, ['id' => 'post-1']);

$session->sync($user);
$session->flush();
```

Explanation:

```text
Use identify() when the developer only wants to refer to an existing row by key.
This does not require querying the row and does not imply scalar updates.
```

`Session::identify($collection, $key)` attaches a representation to an existing row known by key without querying. Without a representation it creates a key-only `stdClass` using same-name primary-key paths. The resulting record is clean, not new or dirty, and can be reused for relation linking, deletion, or unlinking.

Use `Session::existing($object)` when you already have a real object shape and want graph sync to treat it as an existing row. Use `identify()` when only the key matters.

## M2M example

The same provenance model applies when `user.posts` is a many-to-many relation. Scalar field provenance comes from the post projection; relation intent comes from adding the post object to `user.posts`.

### New M2M item discovered through a queried user

```php
$u = $runtime->query($users);

$user = $u
    ->select($u->id, $u->name)
    ->posts
    ->fields('id', 'title')
    ->to(stdClass::class)
    ->mutable($session)
    ->fetchOne();

$post = new stdClass();
$post->title = 'New M2M post';

$user->posts[] = $post;

$session->sync($user);
$session->flush();
```

### Existing M2M item from query

```php
$p = $runtime->query($posts);

$post = $p
    ->select($p->id, $p->title)
    ->where(x()->eq($p->id, 'post-1'))
    ->to(stdClass::class)
    ->mutable($session)
    ->fetchOne();

$user->posts[] = $post;

$session->sync($user);
$session->flush();
```

For M2M:

```text
Scalar field provenance comes from the post projection.
Relation intent comes from adding the post object to user.posts.
Flush can then insert/update the post record and insert the through row as needed.
```

## Boundary: queried projection versus new flat scalar

`SelectQuery` solves field targets for data it selected. It does not magically make a brand-new flattened scalar into a new related record.

This is not enough by itself:

```php
$user->newPostTitle = 'New post';

$session->sync($user);
```

There is no concrete post item and no relation item identity. Use a manual mutable projection to supply that identity:

```php
$p = $session->projection($user);
$u = $p->from($users)->tracked();
$post = $p->create($u->posts);
$p->properties($post->title->as('newPostTitle'))->end();
```

A mutable query projection can update fields whose provenance the query declared. It can also admit new related objects through explicit relation schemas on a tracked root. Manual projections cover standalone flat scalars by requiring the developer to create, identify, or reuse the concrete related record item explicitly.

## Rules

```text
Data came from mutable SelectQuery:
  query provenance + hidden identities can create field targets.

New entity-shaped related object added to a tracked relation:
  relation schema gives context; object defaults to NEW.

Existing object came from mutable SelectQuery:
  already tracked; adding it to a relation creates relation intent.

Existing key-only row:
  use identify(collection, key).

Brand-new flat scalar that did not come from a query:
  use Session::projection($object) with from(), create()/existing()/tracked(), and properties().
```

## Related reading

- [`../query/query-model.md`](../query/query-model.md)
- [`../query/bound-execution.md`](../query/bound-execution.md)
- [`representation-schema.md`](./representation-schema.md)
- [`persistence.md`](./persistence.md)
- [`manual-mutable-projections.md`](./manual-mutable-projections.md)
