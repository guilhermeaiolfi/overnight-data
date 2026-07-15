# Upgrade Notes

`guilhermeaiolfi/overnight-data` is still evolving. This file is **not** a compatibility guarantee.

## What documentation means

Docs describe **how the code works today**, so you can use and debug it.

They are **not**:

- a support contract
- a stability or “safe to depend on” list
- a promise that examples or type names will stay the same

Anything under `ON\Data\` may move, rename, or change behavior. `@internal` markers, if present, are optional hints only; their absence does not mean a type is stable.

Typical integration paths people start from today: `CycleRuntimeFactory` / `DataRuntime`, `SelectQuery`, and `Session`. That is convenience for orientation, **not** a supported public surface.

## Expectation

There is no promise that application code will keep working across releases without changes.

- Types under `ON\Data\` may move, rename, or change behavior.
- Documented examples may change when the code changes.
- Version tags may include breaking changes when correcting design or closing gaps — including what look like minor or patch bumps.
- Pin an exact version or a very narrow constraint and treat upgrades as deliberate work.

```bash
composer require guilhermeaiolfi/overnight-data:1.1.1
```

## Version tags

Git tags use the `vMAJOR.MINOR.PATCH` form (for example `v1.1.1`) so releases are identifiable. The numbers communicate rough scope of change when useful; they are **not** a SemVer contract for this package today.

## How to upgrade

1. Read [`CHANGELOG.md`](CHANGELOG.md) for the releases you are crossing.
2. Diff against your pin and update call sites.
3. Run your test suite and static analysis after upgrading.

Notable recent breaks worth checking explicitly:

- `Session::flush()` / `FlushExecutor` require `TransactionalCommandExecutorInterface`. Plain `CommandExecutorInterface` implementations are rejected with `NonTransactionalFlushException`.

## Reporting issues

If a release breaks something that the docs still describe as current behavior, open a GitHub issue with the versions you upgraded from and to. That helps fix docs or code; it is not a claim that the old behavior was guaranteed.
