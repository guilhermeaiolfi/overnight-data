# Upgrade & Compatibility Policy

This document describes how `guilhermeaiolfi/overnight-data` handles versioning and backward compatibility.

## Versioning

The package follows [Semantic Versioning](https://semver.org/):

- **MAJOR** — intentional breaking changes to supported public APIs.
- **MINOR** — backward-compatible functionality additions.
- **PATCH** — backward-compatible bug fixes and documentation-only changes.

Git tags use the `vMAJOR.MINOR.PATCH` form, for example `v1.0.0`.

## What counts as public API

The supported public surface for 1.x includes:

- classes, interfaces, and traits under `ON\Data\` shipped in `src/`;
- global functions loaded from `src/Mapper/functions.php` and `src/Query/functions.php`;
- documented behavior in `README.md` and `docs/`.

Internal namespaces, `@internal` symbols, test-only helpers, and undocumented implementation details are not part of the compatibility promise.

## 1.x compatibility expectations

Within the 1.x line you should be able to upgrade patch and minor releases without changing application code, except where release notes call out a deliberate behavior correction.

The 1.0 release stabilizes the current data-layer foundation:

- definition registry and wrappers;
- mapper runtime and conversion gateway;
- query model and bound execution;
- relation loading;
- initial ORM persistence through `Session`, `FlushExecutor`, and Cycle command execution.

Features explicitly marked as limitations in README and docs may evolve in minor releases. That includes ORM persistence boundaries such as cascade policy, generated-key refresh beyond auto-increment primary keys, and mutable export restrictions.

## Installing a specific line

```bash
composer require guilhermeaiolfi/overnight-data:^1.1
```

Pin more tightly when you want only patch updates on the 1.1 line:

```bash
composer require guilhermeaiolfi/overnight-data:~1.1.0
```

The 1.0 line remains installable as `^1.0` / `~1.0.0` for consumers that are not ready to pick up 1.1 ORM representation changes.

## Before upgrading

1. Read the matching section in [`CHANGELOG.md`](CHANGELOG.md).
2. Run your test suite after updating.
3. Re-run static analysis if you depend on PHPStan types from this package.

## Reporting breaking changes

If a release within 1.x removes or changes documented behavior without a clear note in `CHANGELOG.md`, please open an issue on GitHub with the tag you upgraded from and to.
