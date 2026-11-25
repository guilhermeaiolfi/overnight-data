# ADR 0003. Compile parameterized SQL behind a connection interface

Status: Accepted

Supersedes [ADR 0001](0001-database-adapter-boundary.md).

## Context

The Cycle Database adapter already compiles ON\Data expressions, conditions, and much of each SELECT before passing SQL fragments to Cycle builders. Insert readback also calls the driver directly for returned values and affected-row counts. Repeating the translation for each connection library would duplicate ON\Data query and persistence semantics.

## Decision

Keep `DataRuntime`, `QueryExecutorInterface`, `CommandExecutorInterface`, and `TransactionalCommandExecutorInterface` as the application boundary. The built-in SQL executors compile ON\Data query and command objects to SQL with positional parameters. The compiler and its statement types are internal. DML dialects own engine-specific syntax. ON\Data definition metadata supplies physical identifiers, and the compiler quotes each identifier part. Raw SQL fragments and custom SQL functions accept trusted application SQL with separately bound values.

`SqlRuntimeFactory` builds both executors from one `ConnectionInterface`. The connection handles typed parameters, cursor reads, affected-row counts, generated IDs, and transactions. `PdoConnection` is built in. Integrations that use another connection library can implement that interface and share the existing compiler. Applications can also supply their own executors to `DataRuntime`.

`ConversionGateway` continues to own value conversion. The query compiler converts field-context literals to storage values, the result mapper converts query rows to PHP, and `CommandExecutor` handles conversion on the write path.

## Consequences

- Composer requires PHP 8.3 or later. The PDO runtime also needs the PHP extension for the selected database.
- ON\Data owns DML compatibility across database versions. Generated-value readback and transactions require live engine tests for parity with earlier adapters.
- `CycleCollectionPlugin` remains available as metadata for external tools.
- Schemas, migrations, and schema introspection stay with the application or its database tooling.
