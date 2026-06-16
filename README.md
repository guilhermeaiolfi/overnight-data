# Overnight Data

A standalone metadata-driven data layer for PHP.

`ON\Data` currently contains the mechanically extracted definition subsystem from Overnight plus the Phase 1 support layer used for later migrations.

## Status

This repository currently includes:

- the `ON\Data` package scaffold;
- `ON\Data\Support\Dot`;
- `ON\Data\Support\DefinitionNode`;
- the extracted `ON\Data\Definition` subsystem;
- tests and quality tooling.

Future phases will still refactor registry storage, primary-key APIs, and view definitions. Those changes are not implemented yet.

## Namespace

Production code autoloads from the `ON\Data\` namespace.

## Installation

During development, install from the repository with Composer:

```bash
composer config repositories.overnight-data vcs <repository-url>
composer require guilhermeaiolfi/overnight-data:dev-main
```

## Quality Commands

```bash
composer install
composer test
composer analyse
composer check-style
composer check
```
