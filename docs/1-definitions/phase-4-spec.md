# Implementation Task — Phase 4: Replace the Primary-Key Model with `Key`

## Objective

Replace the current field-owned primary-key system with a collection-owned primary-key definition and one simple `ON\Data\Key` value object.

This phase must:

1. Move primary-key declaration from `Field` to `Collection`.
2. Store the ordered primary-key field names directly in each collection definition array.
3. Remove `PrimaryKeyDefinition`.
4. Remove `PrimaryKeyValue`.
5. Introduce `ON\Data\Key`.
6. Support simple and composite primary keys through the same API.
7. Update relation definitions and tests to use the new collection-owned primary-key metadata.
8. Preserve the Registry master-array architecture completed in Phase 3.

This phase must not implement views, queries, FieldType conversion, persistence, REST identifier parsing, or Unit of Work.

---

# 1. Required starting state

Before modifying production code:

1. Inspect the current repository.
2. Read:

   * `docs/phase-1-notes.md`
   * `docs/phase-2-notes.md`
   * `docs/phase-3-notes.md`
   * `docs/phase-3-storage-format.md`
   * `docs/phase-2-public-api.md`
3. Run every existing quality command.
4. Confirm all Phase 1–3 tests pass.
5. Commit Phase 3.
6. Record the Phase 3 ending commit hash.
7. Create `docs/phase-4-notes.md`.

Do not begin Phase 4 while the Phase 3 working tree is uncommitted.

Record in `phase-4-notes.md`:

* Phase 3 ending commit;
* Phase 4 starting commit;
* current configured PHPStan level;
* current test count;
* every production usage of:

  * `PrimaryKeyDefinition`;
  * `PrimaryKeyValue`;
  * `Field::primaryKey()`;
  * `FieldInterface::primaryKey()`;
  * `SchemaTrait::primaryKey()`;
  * `FieldInterface::isPrimaryKey()`;
  * `Collection::getPrimaryKey()`;
  * `Collection::getPrimaryKeyFields()`.

---

# 2. Scope

This phase changes only primary-key definition and resolved-key representation.

The intended model is:

```text
Collection
├── primaryKey: ordered list of field names
├── getPrimaryKey()
├── getPrimaryKeyFields()
├── getPrimaryKeyColumns()
├── getKey()
└── getKeyFromRecord()

Key
├── CollectionInterface
├── ordered associative values
├── getValue()
├── getValues()
├── equals()
└── getHash()
```

Primary-key metadata is collection metadata.

A field may answer whether it participates in the collection primary key, but it no longer owns or stores that status.

---

# 3. Explicit non-goals

Do not implement:

* `ViewDefinition`;
* Registry view APIs;
* generalized Collection/View parent interfaces;
* query objects;
* `FieldRef`;
* `RelationRef`;
* expressions;
* aggregates;
* relation handlers;
* database adapters;
* FieldType execution;
* automatic PHP type normalization;
* Mapper execution;
* mutations;
* `ValueRef`;
* persistence;
* identity map;
* Unit of Work;
* entity mapping;
* REST ID parsing;
* URL-to-Key decoding;
* a separate `KeyCodec`;
* incomplete or deferred Keys;
* query caching;
* public API cleanup unrelated to primary keys;
* changes to the master-array wrapper architecture.

Do not begin Phase 5 automatically.

---

# 4. Collection master-array format

Every collection definition must have one canonical primary-key entry:

```php
[
    'collections' => [
        'users' => [
            'primaryKey' => ['id'],
        ],

        'post_user' => [
            'primaryKey' => [
                'post_id',
                'user_id',
            ],
        ],
    ],
]
```

The array order is canonical.

It determines:

* positional key input order;
* `Key::getValues()` order;
* key hash order;
* relation arity validation;
* primary-key field order;
* primary-key column order.

Do not derive canonical order by scanning fields at runtime.

Do not store primary-key metadata in both the collection and fields.

---

# 5. Collection primary-key API

Update `CollectionInterface` and `Collection`.

Required methods:

```php
interface CollectionInterface
{
    public function primaryKey(string ...$fieldNames): self;

    public function hasPrimaryKey(): bool;

    /**
     * Return canonical ordered field names.
     *
     * @return non-empty-list<string>
     *
     * @throws PrimaryKeyNotDefinedException
     */
    public function getPrimaryKey(): array;

    /**
     * Return the fields in canonical primary-key order.
     *
     * @return non-empty-list<FieldInterface>
     *
     * @throws PrimaryKeyNotDefinedException
     * @throws FieldNotFoundException
     */
    public function getPrimaryKeyFields(): array;

    /**
     * Return the storage columns in canonical primary-key order.
     *
     * @return non-empty-list<string>
     *
     * @throws PrimaryKeyNotDefinedException
     */
    public function getPrimaryKeyColumns(): array;

    public function isCompositePrimaryKey(): bool;

    /**
     * @throws InvalidPrimaryKeyException
     * @throws PrimaryKeyNotDefinedException
     */
    public function getKey(
        Key|array|string|int|float|bool $value,
    ): Key;

    /**
     * Extract primary-key components from a record.
     *
     * @throws InvalidPrimaryKeyException
     * @throws PrimaryKeyNotDefinedException
     */
    public function getKeyFromRecord(
        array $record,
        bool $allowColumnNames = true,
    ): Key;
}
```

Do not use mixed simple/composite return types.

These methods must always return arrays:

```php
getPrimaryKey()
getPrimaryKeyFields()
getPrimaryKeyColumns()
```

even for a one-field key.

---

# 6. `primaryKey()` behavior

Simple identity:

```php
$collection->primaryKey('id');
```

Composite identity:

```php
$collection->primaryKey(
    'post_id',
    'user_id',
);
```

Requirements:

1. At least one field name is required.
2. Empty or whitespace-only field names are rejected.
3. Duplicate field names are rejected.
4. Field names are stored in argument order.
5. Calling `primaryKey()` again replaces the entire previous definition.
6. Fields may be declared after `primaryKey()` in the fluent chain.
7. `primaryKey()` must not require fields to already exist.
8. `primaryKey()` must not infer `id`.
9. `primaryKey()` must not enable auto-increment.
10. `primaryKey()` must not change `filterable`, `required`, `nullable`, or other field metadata.
11. The collection master array is updated immediately.
12. No primary-key status is written to field arrays.

Example:

```php
$registry
    ->collection('users')
        ->primaryKey('id')
        ->field('id', 'integer')
            ->end()
        ->end();
```

must be valid even though the field is declared after the primary key.

Field existence is validated when:

* `getPrimaryKeyFields()` is called;
* `getPrimaryKeyColumns()` is called;
* `getKey()` is called;
* `getKeyFromRecord()` is called;
* Registry validation is added in a later phase.

---

# 7. Missing primary keys

A collection may temporarily have no primary key while being configured.

Its canonical stored value should be:

```php
'primaryKey' => []
```

or an absent key normalized to an empty list during Registry construction.

Use one consistent canonical format.

Required behavior:

```php
$collection->hasPrimaryKey(); // false
```

These methods must throw `PrimaryKeyNotDefinedException`:

```php
$collection->getPrimaryKey();
$collection->getPrimaryKeyFields();
$collection->getPrimaryKeyColumns();
$collection->getKey(1);
$collection->getKeyFromRecord(['id' => 1]);
```

Do not return:

```php
null
[]
```

from getters whose contract requires a defined primary key.

`hasPrimaryKey()` is the optional check.

---

# 8. Remove field-level primary-key configuration

Remove the primary-key configuration method from:

* `Field`;
* `FieldInterface`;
* `SchemaTrait`;
* related schema interfaces;
* any field builder contracts.

Remove calls such as:

```php
$field->primaryKey(true);
```

No deprecated alias is required in the new standalone package.

Do not retain support for both declaration styles.

Remove the field-level primary-key key from canonical field arrays.

Examples of old keys may include:

```text
pk
primaryKey
isPrimaryKey
```

Inspect the real Phase 3 storage format and remove the actual key used.

---

# 9. Derived `Field::isPrimaryKey()`

Keep:

```php
$field->isPrimaryKey();
```

It is a query, not a configuration method.

Implement it from the parent collection:

```php
public function isPrimaryKey(): bool
{
    $collection = $this->getCollection();

    if (!$collection->hasPrimaryKey()) {
        return false;
    }

    return in_array(
        $this->getName(),
        $collection->getPrimaryKey(),
        true,
    );
}
```

Requirements:

* it reads collection metadata;
* it stores no independent state;
* it returns `false` when the collection has no primary key;
* it updates immediately when the collection’s primary key is replaced;
* it works after Registry round-trip restoration.

---

# 10. Old array normalization

The Registry may receive Phase 3 arrays containing field-level primary-key flags.

Support one-time normalization during Registry construction.

## 10.1 New-format collection

When a collection already contains:

```php
'primaryKey' => ['id']
```

use it as authoritative.

Remove obsolete field-level PK flags during normalization.

## 10.2 Old-format collection

When the collection does not contain a defined collection-level primary key:

1. Scan fields in stored insertion order.
2. Detect fields with the old primary-key flag.
3. Build the collection-level ordered primary-key list.
4. Store it under `primaryKey`.
5. Remove the old field flags.

Example old input:

```php
[
    'fields' => [
        'post_id' => [
            'primaryKey' => true,
        ],
        'user_id' => [
            'primaryKey' => true,
        ],
    ],
]
```

canonical output:

```php
[
    'primaryKey' => [
        'post_id',
        'user_id',
    ],
    'fields' => [
        'post_id' => [
            // No primary-key flag.
        ],
        'user_id' => [
            // No primary-key flag.
        ],
    ],
]
```

Use the actual old storage key found in Phase 3.

## 10.3 Conflicting formats

If both formats exist and disagree, throw a focused exception.

Suggested:

```php
ConflictingPrimaryKeyDefinitionException
```

Example conflict:

```php
'primaryKey' => ['uuid']
```

while field flags identify:

```php
['id']
```

Do not silently choose one.

If both formats describe the same ordered fields, accept and normalize to the new format.

## 10.4 Read normalization

After Registry construction:

```php
$registry->all()
```

must contain only canonical collection-level primary-key metadata.

A read-only collection or field lookup must not perform further primary-key mutations.

---

# 11. Remove old primary-key classes

Delete:

```text
PrimaryKeyDefinition.php
PrimaryKeyValue.php
```

Remove their tests or replace them with `Key` and Collection tests.

Remove all imports, references, return types, and PHPDoc references.

Add a dependency/architecture test proving production source contains no references to:

```text
PrimaryKeyDefinition
PrimaryKeyValue
```

Do not retain compatibility wrappers.

Document the intentional API break in:

```text
docs/phase-4-notes.md
docs/phase-2-public-api.md
```

---

# 12. `ON\Data\Key`

Create:

```php
namespace ON\Data;

use JsonSerializable;
use ON\Data\Definition\Collection\CollectionInterface;
use Stringable;

final readonly class Key implements Stringable, JsonSerializable
{
    /**
     * @param non-empty-array<string, string|int|float|bool> $values
     */
    public function __construct(
        private CollectionInterface $collection,
        private array $values,
    ) {
    }

    public function getCollection(): CollectionInterface;

    /**
     * Return the only value.
     *
     * @throws CompositeKeyException
     */
    public function getValue(): string|int|float|bool;

    /**
     * @return non-empty-array<string, string|int|float|bool>
     */
    public function getValues(): array;

    /**
     * @throws InvalidPrimaryKeyException
     */
    public function getFieldValue(
        string $fieldName,
    ): string|int|float|bool;

    public function isComposite(): bool;

    public function equals(self $other): bool;

    public function getHash(): string;

    public function getDebugString(): string;

    public function jsonSerialize(): array;

    public function __toString(): string;
}
```

If the project PHP baseline does not support readonly classes, use a final immutable class with private properties instead.

Do not raise the PHP version merely for `readonly`.

---

# 13. Key value restrictions

Until FieldType conversion is migrated, Key values must already be scalar canonical values.

Allowed component values:

```text
string
int
float
bool
```

Reject:

* null;
* arrays;
* arbitrary objects;
* resources;
* closures.

Throw `InvalidPrimaryKeyException`.

Do not call:

```text
(string) $value
```

implicitly.

Do not normalize:

```php
'10'
```

to:

```php
10
```

in this phase.

Type normalization belongs to the future FieldType integration.

Strict type distinction must remain:

```php
$collection->getKey(1)
    ->equals($collection->getKey('1'));
```

returns:

```php
false
```

---

# 14. Key constructor validation

Even though application code should normally use `Collection::getKey()`, the public constructor must preserve invariants.

Validate:

1. The collection has a primary key.
2. Every primary-key field is represented.
3. No unexpected field is represented.
4. Values use canonical field names, not columns.
5. Values are ordered into canonical collection primary-key order.
6. Every value is an allowed scalar.
7. No value is null.

The constructor may reorder an associative array into canonical order.

It must not accept positional arrays directly.

Positional input belongs to `Collection::getKey()`.

---

# 15. `Key::getCollection()`

Return:

```php
CollectionInterface
```

Use the agreed getter naming convention:

```php
$key->getCollection();
```

Do not use:

```php
$key->collection();
```

The Key retains the collection wrapper used to create it.

`Collection::getKey($existingKey)` may rebind it to the current collection wrapper as specified later.

---

# 16. `Key::getValue()`

For a simple key:

```php
$key = $users->getKey(10);

$key->getValue(); // 10
```

For a composite key:

```php
$key->getValue();
```

throws:

```php
CompositeKeyException
```

Do not return the first component.

---

# 17. `Key::getValues()`

Always return a named associative array in canonical primary-key order.

Simple:

```php
[
    'id' => 10,
]
```

Composite:

```php
[
    'post_id' => 10,
    'user_id' => 4,
]
```

Do not return a scalar for simple keys.

---

# 18. `Key::getFieldValue()`

Example:

```php
$key->getFieldValue('post_id');
```

returns the matching value.

Unknown or non-key fields throw:

```php
InvalidPrimaryKeyException
```

Do not accept column names here.

A Key always exposes canonical field names.

---

# 19. Key equality

Two Keys are equal when:

1. Their collection names are strictly equal.
2. Their ordered named values are strictly equal.

Implementation concept:

```php
public function equals(self $other): bool
{
    return $this->collection->getName()
        === $other->collection->getName()
        && $this->values === $other->values;
}
```

Do not require the same Collection object identity.

This allows equivalent Keys created from a Registry before and after array round-trip restoration to compare equal.

Do not compare only values.

These must be unequal:

```php
$users->getKey(1);
$posts->getKey(1);
```

---

# 20. Key hash

`getHash()` must return a deterministic, unambiguous, versioned string suitable for:

* PHP array keys;
* identity maps;
* internal caches;
* logs where machine stability matters.

Construct a canonical payload:

```php
[
    'collection' => $collection->getName(),
    'values' => [
        'post_id' => 10,
        'user_id' => 4,
    ],
]
```

Encode it using JSON with:

```php
JSON_THROW_ON_ERROR
| JSON_UNESCAPED_SLASHES
| JSON_UNESCAPED_UNICODE
| JSON_PRESERVE_ZERO_FRACTION
```

Encode the JSON using unpadded base64url.

Prefix the result:

```text
k1:
```

Example form:

```text
k1:eyJjb2xsZWN0aW9uIjoicG9zdF91c2VyIiwi...
```

`__toString()` returns `getHash()`.

Requirements:

* same logical Key produces the same hash after Registry round trip;
* different collection produces a different hash;
* different field value types produce different hashes;
* composite field order is canonical;
* separators inside string values cannot cause ambiguity.

Do not implement hash decoding in this phase.

---

# 21. Debug string

`getDebugString()` returns a human-readable diagnostic string.

Examples:

```text
users#id=10
post_user#post_id=10,user_id=4
```

Requirements:

* intended only for logs/debugging;
* not used as the identity-map key;
* not guaranteed to be reversible;
* must clearly include collection and field names.

String values should be represented safely enough to distinguish obvious delimiters, for example through JSON encoding of individual values.

Do not make `__toString()` return the debug form.

---

# 22. JSON serialization

`jsonSerialize()` returns:

```php
[
    'collection' => 'post_user',
    'values' => [
        'post_id' => 10,
        'user_id' => 4,
    ],
]
```

Do not serialize the Collection object.

Do not return only the hash.

The result must contain only plain data.

---

# 23. `Collection::getKey()`

## 23.1 Scalar simple key

Allowed only when the collection has one primary-key field:

```php
$users->getKey(10);
```

produces:

```php
[
    'id' => 10,
]
```

Passing a scalar to a composite collection throws `InvalidPrimaryKeyException`.

## 23.2 Associative input

Simple:

```php
$users->getKey([
    'id' => 10,
]);
```

Composite:

```php
$postUsers->getKey([
    'post_id' => 10,
    'user_id' => 4,
]);
```

Requirements:

* all fields required;
* no extra fields;
* input order irrelevant;
* returned Key values reordered canonically;
* canonical field names preferred.

## 23.3 Positional input

Composite positional input:

```php
$postUsers->getKey([10, 4]);
```

maps according to:

```php
$postUsers->getPrimaryKey();
```

Simple positional input:

```php
$users->getKey([10]);
```

may be accepted and mapped to `id`.

Requirements:

* input must be a list;
* number of values must exactly equal PK arity;
* values map in canonical PK order.

## 23.4 Column-name input

For compatibility with current extraction behavior, associative input may use storage column names:

```php
$users
    ->field('id', 'integer')
    ->column('user_id')
    ->end();

$key = $users->getKey([
    'user_id' => 10,
]);
```

The resulting Key uses the canonical field name:

```php
[
    'id' => 10,
]
```

Rules:

* field names take precedence;
* supplying both a field name and its column name is invalid;
* ambiguous columns are invalid;
* mixed field and column names may be accepted when unambiguous;
* resulting Key never stores column names.

## 23.5 Existing Key input

```php
$key = $users->getKey($existingKey);
```

If collection names differ, throw `InvalidPrimaryKeyException`.

If the existing Key uses the exact same Collection wrapper, return the same Key object.

If the collection name matches but the Collection wrapper differs, create a new Key bound to the current Collection using the existing values.

This supports Registry round-trip scenarios while keeping `getCollection()` canonical to the current Registry.

---

# 24. `Collection::getKeyFromRecord()`

Input:

```php
$collection->getKeyFromRecord($record);
```

The record may contain unrelated fields.

Extract only primary-key components.

When `$allowColumnNames` is true:

1. Check canonical field name.
2. If absent, check the field’s storage column.
3. Reject conflicting field and column values.
4. Canonicalize to field names.

When false:

* use field names only.

Missing components throw `InvalidPrimaryKeyException`.

Extra record fields are ignored.

Example:

```php
$key = $postUsers->getKeyFromRecord([
    'post_id' => 10,
    'user_id' => 4,
    'created_at' => '...',
]);
```

Do not add object extraction in this phase.

Mapper/Representation integration will add broader record extraction later.

---

# 25. Field columns

`getPrimaryKeyColumns()` must return each primary-key field’s storage column.

If a field has no explicit column and current Field behavior defaults its column to its field name, preserve that behavior.

Example:

```php
$users
    ->field('id', 'integer')
    ->column('user_id')
    ->end();

$users->getPrimaryKeyColumns();
```

returns:

```php
['user_id']
```

Composite columns preserve canonical PK field order.

---

# 26. Relation integration

Update every relation class that currently interacts with:

* `PrimaryKeyDefinition`;
* `PrimaryKeyValue`;
* `getPrimaryKeyFields()`;
* primary-key arity;
* default inner keys;
* default outer keys;
* target collection identity.

Use the new Collection API.

## 26.1 Arity

Relation validation must compare:

```php
count($relation->innerKeys())
count($relation->outerKeys())
```

and, where applicable:

```php
count($collection->getPrimaryKey())
```

No code may assume one primary-key field.

## 26.2 Defaults

Where a relation currently defaults a key side to a collection primary key, use:

```php
$collection->getPrimaryKey()
```

which always returns an ordered list.

For APIs requiring scalar-or-array compatibility:

* one field may still be exposed through the current singular getter;
* multiple fields use the existing plural getter;
* singular getters continue throwing on composite keys.

## 26.3 M2M

Verify:

* simple owner → simple target;
* composite owner → simple target;
* simple owner → composite target;
* composite owner → composite target;
* composite pivot primary key;
* explicit pivot key mappings;
* existing `M2MThrough` behavior.

Do not introduce generic relation mutation behavior.

---

# 27. Primary-key-related exceptions

Add or preserve focused exceptions:

```text
PrimaryKeyNotDefinedException
InvalidPrimaryKeyException
CompositeKeyException
ConflictingPrimaryKeyDefinitionException
```

Place them consistently with the current exception namespace.

Suggested namespace:

```php
ON\Data\Definition\Exception
```

or preserve the actual existing exception layout if more consistent.

Use:

* `PrimaryKeyNotDefinedException` when a collection has no key;
* `InvalidPrimaryKeyException` for malformed values, missing fields, extra fields, wrong collection, unsupported component types, and invalid scalar/composite usage;
* `CompositeKeyException` when a simple-only operation such as `getValue()` is used on a composite Key;
* `ConflictingPrimaryKeyDefinitionException` for incompatible old/new stored metadata.

Do not use generic exceptions where these apply.

---

# 28. Fluent definition examples

Simple:

```php
$registry
    ->collection('users')
        ->primaryKey('id')

        ->field('id', 'integer')
            ->autoIncrement(true)
            ->end()

        ->field('name', 'string')
            ->end()
        ->end();
```

Composite:

```php
$registry
    ->collection('post_user')
        ->primaryKey('post_id', 'user_id')

        ->field('post_id', 'integer')
            ->end()

        ->field('user_id', 'integer')
            ->end()

        ->field('created_at', 'datetime')
            ->end()
        ->end();
```

Do not add `->primaryKey()` back to fields.

---

# 29. Master-array round trip

Build a Registry containing:

* collection without PK;
* simple PK collection;
* composite PK collection;
* aliased PK columns;
* simple relations;
* composite relations;
* M2M relation.

Then:

```php
$array = $registry->all();
$restored = new Registry($array);
```

Required:

```php
$restored->all() === $array;
```

Also verify:

```php
$restored
    ->getCollection('post_user')
    ->getPrimaryKey();
```

returns:

```php
['post_id', 'user_id']
```

Keys created before and after restoration must:

* compare equal;
* have equal hashes;
* expose different Collection wrappers where expected;
* remain canonically ordered.

Read-only access must not mutate the canonical array.

---

# 30. Tests

Keep all Phase 1–3 tests unless they explicitly characterize the old primary-key API.

Replace old PK tests rather than weakening them.

## 30.1 Collection PK declaration tests

Cover:

* simple declaration;
* composite declaration;
* canonical order;
* replacement;
* zero fields;
* empty field name;
* whitespace field name;
* duplicate field name;
* declaration before fields;
* declaration after fields;
* missing field during resolution;
* no automatic `id`;
* no field metadata side effects;
* `hasPrimaryKey()`;
* missing-PK exceptions.

## 30.2 Field derivation tests

Cover:

* simple PK field;
* composite PK fields;
* non-PK field;
* collection without PK;
* replacing collection PK;
* Registry restoration;
* no field-level PK value in `all()`.

## 30.3 Old-array migration tests

Cover:

* new format only;
* old format only;
* matching new and old formats;
* conflicting formats;
* composite order from field insertion order;
* old field flags removed;
* canonical `all()` output;
* no additional mutations after construction.

## 30.4 Key construction tests

Cover:

* simple scalar;
* simple associative;
* simple positional;
* composite associative;
* composite positional;
* input reordering;
* column-name input;
* mixed field/column input;
* duplicate field/column specification;
* missing component;
* extra component;
* scalar for composite;
* unsupported value;
* null;
* existing Key same wrapper;
* existing Key equivalent collection;
* existing Key wrong collection.

## 30.5 Key accessor tests

Cover:

* `getCollection()`;
* `getValue()` simple;
* `getValue()` composite exception;
* `getValues()` simple;
* `getValues()` composite;
* `getFieldValue()`;
* unknown field;
* `isComposite()`.

## 30.6 Equality tests

Cover:

* same simple Key;
* different simple value;
* int versus string;
* same composite Key;
* different component;
* different collection;
* Registry round-trip equality;
* associative input order independence.

## 30.7 Hash tests

Cover:

* deterministic output;
* `k1:` prefix;
* simple and composite keys;
* type distinction;
* value separator safety;
* Unicode;
* floats preserving `.0`;
* field-order canonicalization;
* Registry round-trip stability;
* `__toString()` equality with `getHash()`.

## 30.8 Debug string tests

Cover:

* simple;
* composite;
* string delimiters;
* field names;
* collection name.

## 30.9 JSON tests

Verify exact plain-data structure.

## 30.10 Record extraction tests

Cover:

* field names;
* column names;
* field-name preference;
* conflict;
* missing component;
* extra fields ignored;
* composite extraction;
* `allowColumnNames=false`.

## 30.11 Relation tests

Cover every combination relevant to current relation types:

* simple PK relations;
* composite PK relations;
* key arity validation;
* singular getters;
* plural getters;
* default key inference;
* M2M combinations;
* Registry round trip.

## 30.12 Architecture tests

Verify production source contains no references to:

```text
PrimaryKeyDefinition
PrimaryKeyValue
```

Verify field arrays in `Registry::all()` contain no primary-key status key.

Verify all primary-key metadata is at collection level.

---

# 31. Update existing tests and fixtures

Search every test and fixture for old usage:

```php
->primaryKey(true)
```

Replace it with collection-level declarations.

Example old:

```php
$registry
    ->collection('users')
        ->field('id', 'integer')
            ->primaryKey(true)
            ->end()
        ->end();
```

New:

```php
$registry
    ->collection('users')
        ->primaryKey('id')
        ->field('id', 'integer')
            ->end()
        ->end();
```

Do not add temporary compatibility methods merely to avoid updating tests.

Delete old `PrimaryKeyDefinitionTest` and `PrimaryKeyValueTest` after their relevant behavior has been replaced by new tests.

---

# 32. Public API documentation

Regenerate:

```text
docs/phase-2-public-api.md
```

or create a Phase 4 API snapshot if the project now keeps per-phase snapshots.

Document these intentional breaks:

```text
Field::primaryKey() removed
FieldInterface::primaryKey() removed
Collection::primaryKey() added
Collection::hasPrimaryKey() added
Collection::getPrimaryKey() now returns list<string>
Collection::getPrimaryKeyFields() always returns list<FieldInterface>
Collection::getPrimaryKeyColumns() added
Collection::getKey() added
Collection::getKeyFromRecord() added
PrimaryKeyDefinition removed
PrimaryKeyValue removed
ON\Data\Key added
```

Document any additional real deviations.

---

# 33. PHPStan policy

The Phase 3 notes report that PHPStan level 1 passes.

Do not lower the configured level.

Do not introduce:

* a baseline;
* broad ignore rules;
* broad `mixed` substitutions.

Run:

```bash
composer analyse
```

Also run PHPStan manually at level 1.

Both must pass.

Do not raise beyond level 1 in this phase.

If the configured level is still 0, leave changing the configuration to a separate cleanup commit unless the change is trivial and explicitly documented.

---

# 34. Internal storage API containment

Do not add new public hydration or rebinding APIs.

Existing Phase 3 infrastructure such as:

```php
Collection::bindDefinitionArray()
```

and optional constructor hydration parameters may remain temporarily.

Do not use them in the new public `Key` API.

Mark infrastructure methods with:

```php
/** @internal */
```

when they are public only because of current construction mechanics and when doing so does not affect runtime behavior.

Do not redesign the wrapper factory architecture during this phase.

Record these APIs as cleanup candidates in `phase-4-notes.md`.

---

# 35. Documentation

Create:

```text
docs/phase-4-notes.md
docs/phase-4-key-format.md
```

Update:

```text
docs/phase-3-storage-format.md
docs/phase-2-public-api.md
README.md
```

## `phase-4-notes.md`

Record:

1. Starting and ending commits.
2. Files changed.
3. Old API usages found.
4. Migration normalization rules.
5. Field-level key removed.
6. Relation changes.
7. API breaks.
8. Tests replaced.
9. Tests added.
10. PHPStan results.
11. Issues deferred to views.
12. Issues deferred to FieldType integration.
13. Internal storage API cleanup candidates.

## `phase-4-key-format.md`

Document:

* collection primary-key storage;
* canonical ordering;
* accepted `getKey()` inputs;
* component value restrictions;
* equality;
* hash format;
* debug format;
* JSON format;
* Registry round-trip behavior;
* unsupported decoding;
* future FieldType normalization boundary.

Do not claim hash strings are URL IDs.

---

# 36. Implementation order

Follow this order.

## Step 1 — Baseline and inventory

1. Commit Phase 3.
2. Run all existing quality checks.
3. Inventory old primary-key APIs and usages.
4. Document current storage keys.

## Step 2 — Collection metadata

1. Add collection-level primary-key storage.
2. Add `primaryKey()`.
3. Add primary-key getters.
4. Add `hasPrimaryKey()`.
5. Add missing/invalid exceptions.
6. Add tests.

## Step 3 — Old-array normalization

1. Detect old field flags.
2. Migrate them into collection metadata.
3. Detect conflicts.
4. Remove old flags.
5. Test canonical output.

## Step 4 — Field cleanup

1. Remove field configuration method.
2. Remove field storage.
3. Derive `isPrimaryKey()`.
4. Update fixtures and fluent definitions.
5. Test no duplicated metadata.

## Step 5 — Key

1. Add `ON\Data\Key`.
2. Implement invariants and accessors.
3. Implement equality.
4. Implement hash.
5. Implement debug and JSON representations.
6. Add unit tests.

## Step 6 — Collection Key factories

1. Implement `getKey()`.
2. Implement positional mapping.
3. Implement field/column canonicalization.
4. Implement existing-Key rebinding.
5. Implement `getKeyFromRecord()`.
6. Add tests.

## Step 7 — Remove old classes

1. Update every usage.
2. Delete old classes.
3. Delete obsolete tests.
4. Add architecture guard.

## Step 8 — Relations

1. Update relation PK APIs.
2. Validate composite arity.
3. Update defaults.
4. Test M2M combinations.
5. Confirm round trip.

## Step 9 — Documentation and quality

1. Update storage documentation.
2. Regenerate API snapshot.
3. Run every quality command.
4. Commit Phase 4.
5. Stop.

---

# 37. Definition of done

Phase 4 is complete only when:

1. Phase 3 is committed.
2. Every collection stores its PK as an ordered field-name list.
3. Field arrays contain no primary-key status.
4. `primaryKey()` exists only on Collection.
5. `Field::isPrimaryKey()` is derived.
6. `hasPrimaryKey()` works.
7. Missing-PK operations throw focused exceptions.
8. `getPrimaryKey()` always returns a list.
9. `getPrimaryKeyFields()` always returns a list.
10. `getPrimaryKeyColumns()` always returns a list.
11. `PrimaryKeyDefinition` is deleted.
12. `PrimaryKeyValue` is deleted.
13. Production source contains no references to them.
14. `ON\Data\Key` exists.
15. Key supports simple and composite identities.
16. Key values are canonical named arrays.
17. `getValue()` works only for simple keys.
18. Equality distinguishes collection and value types.
19. Hash is deterministic and unambiguous.
20. JSON serialization contains only collection name and values.
21. `Collection::getKey()` accepts all specified forms.
22. `getKeyFromRecord()` works with fields and optional columns.
23. Old Phase 3 arrays normalize into the new structure.
24. Conflicting old/new definitions throw.
25. Relations use collection-owned PK metadata.
26. Composite relation behavior remains.
27. M2M composite cases are tested.
28. Registry round trip remains exact.
29. Read-only access does not mutate canonical data.
30. No ViewDefinition exists.
31. No query, adapter, persistence, or ORM feature has been added.
32. Phase 1 tests pass.
33. Phase 2 non-obsolete tests pass.
34. Phase 3 tests pass after intentional PK updates.
35. New Phase 4 tests pass.
36. PHPStan passes at configured level.
37. PHPStan level 1 passes.
38. Coding-style checks pass.
39. Dependency guards pass.
40. Phase 5 has not started.

---

# 38. Final response

At completion, report:

* Phase 3 ending commit;
* Phase 4 starting and ending commits;
* files changed;
* old PK usages found;
* old-array migration behavior;
* final collection PK storage format;
* final `Key` API;
* hash format;
* relation changes;
* old classes removed;
* tests replaced;
* tests added;
* all quality commands and results;
* PHPStan configured-level and level-1 results;
* deviations from this specification;
* concerns for ViewDefinition;
* concerns for FieldType normalization;
* internal infrastructure cleanup candidates.

Do not begin Phase 5.

Stop after Phase 4.
