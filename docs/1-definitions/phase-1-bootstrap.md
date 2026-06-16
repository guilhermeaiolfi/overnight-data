# Implementation Task — Phase 1: Bootstrap `ON\Data`

## Objective

Create the standalone `overnight-data` package that will receive the data-definition system currently located in Overnight.

This phase is only package bootstrapping and minimal support infrastructure.

Do **not** migrate or refactor the ORM definition classes yet.

The package must:

* use the root namespace `ON\Data`;
* install independently from Overnight;
* contain the minimal array/configuration utilities required by the later Registry migration;
* use testing, static-analysis, and coding-style tooling compatible with the current Overnight project;
* contain no dependency on Cycle, Doctrine, or Overnight itself.

---

# 1. Source repository to inspect

Use this branch as the reference:

```text
https://github.com/guilhermeaiolfi/overnight/tree/cycle-mutation
```

Before modifying the new repository, inspect at least:

```text
composer.json
phpunit configuration
static-analysis configuration
coding-style configuration
src/Config/
```

In particular, inspect the existing Overnight implementations of:

```text
ON\Config\Config
ON\Config\Dot
```

Also inspect a class such as:

```text
ON\View\ViewConfig
```

to understand how Config-backed domain objects are currently used.

Do not assume the exact API from this prompt when the current Overnight implementation already defines equivalent behavior. Preserve the existing method names and semantics where practical.

---

# 2. Repository identity

Use:

```text
Repository: overnight-data
Composer package: guilhermeaiolfi/overnight-data
Root namespace: ON\Data
```

The package name may be adjusted only if the new repository already contains a different valid Composer package name.

Configure PSR-4:

```json
{
    "autoload": {
        "psr-4": {
            "ON\\Data\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\ON\\Data\\": "tests/"
        }
    }
}
```

Use the same minimum PHP version as the current Overnight `cycle-mutation` branch.

Do not raise the minimum PHP version unless a concrete incompatibility requires it. Document any such incompatibility instead of changing it silently.

---

# 3. Required package files

Create or configure at least:

```text
composer.json
README.md
LICENSE
.gitignore
.editorconfig

phpunit.xml.dist

src/
tests/
docs/
```

Also add the static-analysis and coding-style configuration used by Overnight, adapted to this repository.

Possible files include:

```text
phpstan.neon.dist
ecs.php
php-cs-fixer.php
```

Use the tooling already adopted by Overnight. Do not introduce a different formatter or analyzer merely by preference.

Add useful Composer scripts matching the actual installed tools, for example:

```json
{
    "scripts": {
        "test": "...",
        "analyse": "...",
        "check-style": "...",
        "fix-style": "...",
        "check": [
            "@test",
            "@analyse",
            "@check-style"
        ]
    }
}
```

Do not add script names whose commands do not work.

---

# 4. Production dependencies

The package must not require:

```text
guilhermeaiolfi/overnight
cycle/orm
cycle/database
doctrine/orm
doctrine/dbal
```

It must also not reference production namespaces beginning with:

```text
Cycle\
Doctrine\
ON\ORM\
ON\RestApi\
```

Development dependencies may include only ordinary development tools such as:

* PHPUnit;
* PHPStan;
* the coding-style tool already used by Overnight.

Do not add a framework container, event dispatcher, database library, mapper, or collection library in this phase.

---

# 5. Minimal support namespace

Create:

```text
src/Support/
```

with the minimal standalone equivalents of the current Overnight configuration utilities:

ON\Data\Support\Dot
ON\Data\Support\DefinitionNode (akin to the Config class in overnight)

These classes will later support the Registry-owned master array.

Do not migrate the entire Overnight Config package. Copy or adapt only the behavior required by this package.

---

# 6. `Dot` requirements

`Dot` must provide the current Overnight dot-path behavior needed by Config.

At minimum, support:

* retrieving a nested value;
* checking whether a nested path exists;
* setting a nested value;
* optionally removing a nested value if the current Overnight implementation already supports it;
* retrieving the root array when no path is supplied, if that is current behavior.

Example expected behavior:

```php
$data = [
    'collections' => [
        'users' => [
            'table' => 'users',
        ],
    ],
];

assert(
    Dot::get($data, 'collections.users.table')
        === 'users'
);

assert(
    Dot::has($data, 'collections.users.table')
        === true
);

Dot::set(
    $data,
    'collections.users.primaryKey',
    ['id'],
);

assert(
    $data['collections']['users']['primaryKey']
        === ['id']
);
```

Requirements:

* dots delimit nested array segments;
* a missing value may return a supplied default;
* setting a nested value creates missing intermediate arrays;
* behavior must be deterministic;
* methods must have precise PHPDoc/static-analysis types where useful;
* do not add wildcard, glob, query-language, or object-property behavior.

If the current Overnight `Dot` API uses instance methods rather than static methods, preserve that style instead of changing it without reason.

---

# 7. `Config` requirements

`Config` must be a small generic array-backed object suitable as the base for future definition nodes.

It must support the equivalent of:

```php
get(...)
set(...)
has(...)
all()
```

It must also support:

```php
IteratorAggregate
JsonSerializable
```

Expected behavior:

```php
$config = new Config([
    'table' => 'users',
    'primaryKey' => ['id'],
]);

assert($config->get('table') === 'users');
assert($config->has('primaryKey'));
assert($config->all() === [
    'table' => 'users',
    'primaryKey' => ['id'],
]);

$config->set('metadata.hidden', true);

assert(
    $config->get('metadata.hidden') === true
);
```

`jsonSerialize()` must return the same plain array represented by `all()`.

Iteration must iterate over the top-level items.

Do not add domain-specific methods to `Config`.

---

# 8. Array-reference binding

The future Registry will own one master array, while Collection, Field, Relation, and View wrappers will mutate nested portions of that same array.

Therefore, the support layer must provide a safe mechanism for binding a Config object to an existing array by reference.

The exact API may follow the existing Overnight implementation if it already supports this correctly.

Otherwise, introduce a small protected mechanism such as:

```php
protected function bind(array &$items): void
```

or an equivalent constructor/factory intended for subclasses.

Required behavior:

```php
$root = [
    'collections' => [
        'users' => [
            'table' => 'users',
        ],
    ],
];

$node = TestConfigNode::fromReference(
    $root['collections']['users'],
);

$node->set('table', 'app_users');

assert(
    $root['collections']['users']['table']
        === 'app_users'
);
```

The reverse direction must also work:

```php
$root['collections']['users']['table'] = 'users_v2';

assert(
    $node->get('table')
        === 'users_v2'
);
```

Important constraints:

* do not copy the nested array when binding;
* do not require objects inside the array;
* do not use global state;
* do not use static storage for bound arrays;
* do not serialize references;
* do not expose references through the public domain API;
* keep the implementation small and testable.

The reference-binding API may be protected or internal. It does not need to become a broadly advertised public feature.

---

# 9. Plain-array guarantee

Add a reusable test helper that recursively checks whether an array contains any object or resource.

Example concept:

```php
assertPlainData($config->all());
```

Allowed values:

```text
array
string
int
float
bool
null
```

Class names stored as strings are allowed.

Objects, resources, and closures are not allowed.

This helper will later be reused for Registry round-trip tests.

Place it in the test namespace, not production code, unless a production validator is already clearly justified.

---

# 10. Tests

Create focused unit tests for `Dot` and `Config`.

At minimum, test the following.

## 10.1 Dot tests

* read an existing top-level key;
* read an existing nested key;
* return a default for a missing key;
* distinguish an existing `null` value from a missing path;
* check an existing path;
* reject or report a missing path correctly;
* set a top-level key;
* set a nested key;
* create missing intermediate arrays;
* overwrite an existing value;
* preserve unrelated values;
* remove a path if removal is supported.

## 10.2 Config tests

* construct from an array;
* return all items;
* retrieve a top-level value;
* retrieve a nested value;
* return a default;
* detect an existing value;
* set a top-level value;
* set a nested value;
* iterate over top-level values;
* JSON serialize to the same array;
* contain no production objects in `all()`.

## 10.3 Reference-binding tests

* changes through the bound Config update the original array;
* changes through the original array are visible through Config;
* two wrappers bound to the same array observe the same values;
* replacing a scalar value works;
* replacing a nested array works;
* unrelated sibling nodes remain unaffected;
* constructing an ordinary root Config still owns its supplied array normally.

## 10.4 Static-analysis tests

Static analysis must pass at the configured strictness level.

Do not silence errors broadly using:

```text
ignoreErrors
baseline files
mixed everywhere
```

Targeted annotations are acceptable when PHP’s reference semantics cannot be expressed more precisely.

---

# 11. README

Add a minimal README containing:

```text
# Overnight Data

A standalone metadata-driven data layer for PHP.
```

Document only what exists after this phase:

* package purpose;
* namespace;
* installation from the repository during development;
* current status as an early extraction;
* how to run tests and quality checks.

Do not document query, ORM, view, mutation, or mapping APIs that do not exist yet.

A brief roadmap may mention that collection definitions, semantic views, queries, and ORM support will be introduced in later phases, but do not describe them as already implemented.

---

# 12. Architecture notes

Create:

```text
docs/phase-1-notes.md
```

Record:

1. The PHP version copied from Overnight.
2. The testing/static-analysis/style tools copied from Overnight.
3. The original locations of the copied `Config` and `Dot` implementations.
4. Any behavior that had to change to remove an Overnight dependency.
5. The exact array-reference-binding mechanism selected.
6. Any concern that should be revisited during the Registry migration.
7. Any difference between the actual Overnight source and this specification.

Keep this file factual. Do not turn it into a broad future architecture proposal.

---

# 13. Quality commands

Before completing the phase, run all applicable commands, including the equivalent of:

```bash
composer validate --strict
composer install
composer test
composer analyse
composer check-style
```

If Composer scripts use different names, run the actual configured commands.

All commands must pass.

Do not claim success if a command was not run or failed.

---

# 14. Explicit non-goals

Do not do any of the following in this phase:

* copy `src/ORM/Definition`;
* implement `Registry`;
* implement `Collection`;
* implement `Field`;
* implement relations;
* implement `Key`;
* implement `ViewDefinition`;
* implement primary-key handling;
* implement Mapper or FieldType;
* implement QuerySpec;
* implement query objects;
* implement database adapters;
* add Cycle or Doctrine;
* add REST integration;
* add mutation code;
* add Unit of Work;
* add backward-compatibility aliases for `ON\ORM`;
* create speculative abstractions for later phases;
* modify the original Overnight repository.

This phase must leave the repository ready for mechanical definition extraction, but must not begin that extraction.

---

# 15. Definition of done

The phase is complete only when:

1. The standalone repository has a valid Composer package.
2. `ON\Data\` autoloads from `src/`.
3. Test classes autoload from `tests/`.
4. The package uses the same supported PHP baseline as Overnight.
5. PHPUnit runs successfully.
6. Static analysis runs successfully.
7. Coding-style checks run successfully.
8. `ON\Data\Support\Dot` works and is tested.
9. `ON\Data\Support\Config` works and is tested.
10. Config supports nested access through Dot.
11. Config nodes can bind to an existing array by reference.
12. Bound-node mutations update the original array.
13. Original-array mutations are visible through bound nodes.
14. Config output contains only plain data.
15. There are no production dependencies on Overnight, Cycle, or Doctrine.
16. No definition, query, persistence, or ORM classes have been migrated.
17. `docs/phase-1-notes.md` records implementation decisions and deviations.
18. All configured quality commands pass.

---

# 16. Final response

At completion, report:

* files created or modified;
* Composer package and namespace configuration;
* PHP and tooling versions selected;
* reference-binding approach used;
* tests added;
* commands executed and their results;
* any deviation from the specification;
* any issue that should be resolved before Phase 2.

Do not start Phase 2 automatically.

Stop after Phase 1 is complete.
