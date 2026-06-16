# ON\Data Mapper Phase 1 — Corrective Patch

Repository:

```text
https://github.com/guilhermeaiolfi/overnight-data
```

Required base commit:

```text
ab80c8156aa222e1e41e91ad384e425ad62d5598
FieldType ground work
```

## Scope

Apply a small corrective patch to the completed FieldType Phase 1 implementation.

Do not begin Phase 2.

Do not add:

* `MapperInterface`;
* abstract `Mapper`;
* `MapperManager`;
* `MappingContext`;
* `MapBuilder`;
* `map()`;
* structural mappers.

Do not redesign the completed definition system or the existing Phase 1 architecture.

---

# 1. Integer conversion range safety

Inspect:

```text
src/Mapper/Field/IntFieldType.php
```

The current implementation casts numeric strings and integral floats directly to `int`.

This must not silently truncate, saturate, wrap, or otherwise change values outside the current PHP platform integer range.

## Required behavior

Accept:

* native integers;
* integer strings within `PHP_INT_MIN` through `PHP_INT_MAX`;
* finite integral floats within the safe integer range supported by the implementation.

Reject:

* integer strings outside PHP integer range;
* floats outside PHP integer range;
* non-integral floats;
* non-finite floats;
* malformed numeric strings;
* exponent strings that cannot be represented safely as an integer;
* values whose conversion would be platform-dependent or lossy.

Do not determine validity by casting first and then trusting the result.

Use explicit validation before the final cast.

## Required tests

At minimum test:

```php
(string) PHP_INT_MAX
(string) PHP_INT_MIN
PHP_INT_MAX
PHP_INT_MIN
```

as successful values.

Test clearly out-of-range strings such as:

```php
PHP_INT_MAX . '0'
'-' . PHP_INT_MAX . '0'
'999999999999999999999999999999'
'-999999999999999999999999999999'
```

as failures.

Also test:

```php
1.5
INF
-INF
NAN
```

as failures.

When testing floats near the integer boundary, account for floating-point precision. Do not write a test that assumes a float can exactly represent every integer near `PHP_INT_MAX`.

Preserve the original conversion exception chain according to the existing gateway behavior.

---

# 2. Reject non-finite float values

Inspect:

```text
src/Mapper/Field/FloatFieldType.php
```

The canonical PHP float representation must not accept:

```php
INF
-INF
NAN
```

It must also reject numeric strings that overflow to a non-finite float.

## Required behavior

After normalizing an accepted integer, float, or numeric string, verify:

```php
is_finite($value)
```

Reject non-finite results through the existing invalid-field-value exception model.

Do not silently convert overflowed strings to infinity.

## Required tests

Test direct rejection of:

```php
INF
-INF
NAN
```

Test at least one numeric string that overflows when converted to float, for example:

```php
'1e9999'
'-1e9999'
```

Retain successful tests for ordinary integer, float, decimal-string, and exponent-string inputs where the result is finite.

---

# 3. Correct the documentation getter

Inspect:

```text
docs/2-field-types-and-mapper.md
```

For read-only field access, examples must use:

```php
$collection->getField('id')
```

Do not use:

```php
$collection->field('id')
```

in conversion examples.

`field()` belongs to the fluent definition/configuration API and may create a missing field. Conversion documentation must demonstrate non-mutating reads.

Update the relevant `FieldContext::fromField()` example accordingly.

---

# 4. Make the boolean input policy explicit

Inspect:

```text
src/Mapper/Field/BoolFieldType.php
tests/Mapper/
docs/2-field-types-and-mapper.md
```

Determine the currently accepted boolean forms.

If the implementation accepts:

```text
true
false
1
0
"1"
"0"
"true"
"false"
"yes"
"no"
"on"
"off"
```

retain that behavior only if it is intentional.

Whichever policy is kept must be:

* explicitly documented;
* fully covered by tests;
* case-handling behavior documented;
* whitespace-handling behavior documented;
* ambiguous strings rejected.

Do not broaden the accepted set beyond current intentional behavior.

Do not use generic PHP truthiness.

Add rejection tests for representative ambiguous values such as:

```text
""
"2"
"-1"
"enabled"
"disabled"
"maybe"
```

If compatibility with existing Overnight behavior is the reason for retaining `yes/no/on/off`, state that in the completion report.

---

# 5. Review FieldContext metadata copying

Inspect:

```text
src/Mapper/FieldContext.php
```

`FieldContext::fromField()` may keep an optional live reference to:

```php
ON\Data\Definition\Field\FieldInterface
```

It must not create an unnecessary parallel snapshot of unrelated field-definition metadata.

Review the copied metadata and remove values that have no demonstrated scalar-conversion use in Phase 1.

Do not copy broad UI, relation, validation, display, or definition-configuration state merely because it is available.

Keep only metadata that is currently needed by conversion behavior or is explicitly part of an accepted conversion contract.

The live field remains available through:

```php
getField()
hasField()
```

This metadata reduction is secondary to the integer and float fixes. Do not introduce a large redesign to achieve it.

If no metadata is currently required, an empty metadata array is acceptable.

Add or update tests to confirm:

* `FieldContext::fromField()` does not mutate definitions;
* Registry export remains unchanged;
* the optional field reference is preserved;
* removed metadata was not part of a documented public contract.

---

# 6. Preserve existing architecture

Do not change these established decisions:

* `Mapping` is the only ambient default gateway holder.
* `ConversionGateway` has no static singleton lifecycle.
* `ConversionGateway` performs no application or container lookup.
* Definitions contain no runtime services.
* `null` passes through conversion unchanged.
* Same-representation conversion short-circuits.
* Unsafe `bigprimary` and `decimal` aliases remain unregistered.
* FieldType resolution remains explicit.
* Phase 2 structural mapping remains unimplemented.

Production code must continue to avoid dependencies on:

```text
ON\Application
ON\Container
ON\ORM
ON\RestApi
Cycle
Doctrine
Psr\Http
Psr\Container
```

---

# 7. Quality commands

Run and report every command independently:

```bash
composer validate --strict
composer dump-autoload
composer test
composer analyse
composer check-style
composer check
```

Do not infer a command result from another command.

The known PHP CS Fixer runtime-version warning may be reported when the command still exits successfully.

---

# 8. Commit

After all tests and checks pass, create one focused corrective commit.

Suggested commit message:

```text
Harden primitive FieldType conversion
```

Do not include Phase 2 code.

---

# 9. Completion report

Report:

1. Starting commit SHA.
2. Final commit SHA.
3. Working-tree status.
4. Files changed.
5. Integer range-validation strategy.
6. Float finite-value validation strategy.
7. Final accepted boolean input set.
8. Whether and why `yes/no/on/off` were retained or removed.
9. FieldContext metadata that remains.
10. Documentation corrections.
11. Test count and assertion count.
12. Results of all six quality commands.
13. Any warnings.
14. Confirmation that Phase 2 was not started.

Stop after this report.
