# Upgrade Notes

`guilhermeaiolfi/overnight-data` is still evolving. This file is **not** a compatibility guarantee.

## Expectation

There is no promise that application code will keep working across releases without changes.

- Types under `ON\Data\` may move, rename, or change behavior.
- Documented examples may change when the preferred API changes.
- Minor and patch releases may include breaking changes when correcting design or closing gaps.
- `@internal` markers, if present, are hints only; absence of `@internal` does not mean a type is stable.

If you depend on this package, pin an exact version or a very narrow constraint and treat upgrades as deliberate work.

```bash
composer require guilhermeaiolfi/overnight-data:1.1.1
```

## Version tags

Git tags use the `vMAJOR.MINOR.PATCH` form (for example `v1.1.1`) so releases are identifiable. The numbers communicate rough scope of change when useful; they are **not** a SemVer contract for this package today.

## How to upgrade

1. Read [`CHANGELOG.md`](CHANGELOG.md) for the releases you are crossing.
2. Diff against your pin and update call sites.
3. Run your test suite and static analysis after upgrading.

## Reporting issues

If a release breaks something that the docs still describe as current behavior, open a GitHub issue with the versions you upgraded from and to. That helps fix docs or code; it is not a claim that the old behavior was guaranteed.
